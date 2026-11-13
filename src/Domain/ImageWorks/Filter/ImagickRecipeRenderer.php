<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use Imagick;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Imagick renderer. Each run of consecutive linear steps is folded into one
 * ColorMatrix and applied with a single colorMatrixImage call.
 */
final class ImagickRecipeRenderer implements RecipeRenderer
{
	public function apply(ImageInterface $image, array $steps, FilterContext $context): ImageInterface
	{
		$imagick = $image->core()->native();
		if (!$imagick instanceof \Imagick) {
			throw new \LogicException('ImagickRecipeRenderer needs an Imagick image');
		}

		// Everything that can fail (a color the tables cannot read) is worked
		// out before the first pixel changes, so a failed filter leaves no trace.
		$passes = array_map($this->pass(...), FilterRuns::split($steps));

		foreach ($imagick as $frame) {
			// The math is RGB: on CMYK (or any other space) the matrix and the
			// tables would work on the wrong channels. GD always decodes to RGB.
			if ($frame->getImageColorspace() !== \Imagick::COLORSPACE_SRGB) {
				$frame->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
			}
			foreach ($passes as $pass) {
				$pass($frame);
			}
		}

		return $image;
	}

	/** @return \Closure(\Imagick):void */
	private function pass(ColorMatrix|FilterStep $run): \Closure
	{
		if ($run instanceof ColorMatrix) {
			return fn (\Imagick $frame) => $this->applyMatrix($frame, $run);
		}

		switch ($run->op) {
			case 'overlay':
				$tables = NonlinearSteps::overlayTables($run);

				return fn (\Imagick $frame) => $this->applyTables($frame, $tables);
			case 'gradient-map':
				$tables = NonlinearSteps::gradientTables($run);

				// W3C grayscale(1) uses the same 0.2126 / 0.7152 / 0.0722 luminance weights.
				return function (\Imagick $frame) use ($tables): void {
					$this->applyMatrix($frame, ColorMatrix::grayscale(1.0));
					$this->applyTables($frame, $tables);
				};
			case 'vignette':
				$strength = (float)$run->args[0];
				$color    = NonlinearSteps::rgb((string)($run->args[1] ?? '000000'));

				return fn (\Imagick $frame) => $this->applyVignette($frame, $strength, $color);
			case 'grain':
			case 'leak':
				$map = NonlinearSteps::rowFunction($run);

				return fn (\Imagick $frame) => $this->applyRows($frame, $map);
			default:
				throw new \LogicException(sprintf('Unknown step "%s"', $run->op));
		}
	}

	/**
	 * Maps each color channel through its own 256-entry table with one native
	 * clutImage call. The tables are the same ones GD uses, so the drivers share
	 * the formula; alpha is not in the channel mask and stays as it was.
	 *
	 * @param array{list<int>,list<int>,list<int>} $tables
	 */
	private function applyTables(\Imagick $frame, array $tables): void
	{
		$pixels = [];
		for ($i = 0; $i < 256; $i++) {
			$pixels[] = $tables[0][$i];
			$pixels[] = $tables[1][$i];
			$pixels[] = $tables[2][$i];
		}

		$clut = new \Imagick();
		$clut->newImage(256, 1, 'black');
		$clut->setImageFormat('png');
		$clut->importImagePixels(0, 0, 256, 1, 'RGB', \Imagick::PIXEL_CHAR, $pixels);

		$frame->clutImage($clut, \Imagick::CHANNEL_RED | \Imagick::CHANNEL_GREEN | \Imagick::CHANNEL_BLUE);
		$frame->clampImage();
		$clut->clear();
	}

	/**
	 * ImageMagick has no native radial mask that matches the vignette formula
	 * (a corner-normalized ellipse with a smoothstep falloff), so this one is
	 * computed row by row over the color channels. Alpha is never exported.
	 * It is one of the slow steps on Imagick, so `filterMaxPixels` applies to
	 * filters that contain it (RegisteredFilter::isPixelLimitedOn()).
	 *
	 * @param array{int,int,int} $colors
	 */
	private function applyVignette(\Imagick $frame, float $strength, array $colors): void
	{
		$width  = $frame->getImageWidth();
		$height = $frame->getImageHeight();

		for ($y = 0; $y < $height; $y++) {
			$row    = NonlinearSteps::vignetteRow($y, $width, $height, $strength);
			$pixels = $frame->exportImagePixels(0, $y, $width, 1, 'RGB', \Imagick::PIXEL_CHAR);
			$out    = [];
			foreach ($pixels as $i => $value) {
				$k     = $row[intdiv($i, 3)];
				$v     = (int)round($value * (1 - $k) + $colors[$i % 3] * $k);
				$out[] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
			}
			$frame->importImagePixels(0, $y, $width, 1, 'RGB', \Imagick::PIXEL_CHAR, $out);
		}
	}

	/**
	 * Grain and leak: no native ImageMagick call matches their math (a hashed
	 * noise pattern, a smoothstep corner mask), so they run row by row in PHP
	 * through the same NonlinearSteps code as GD. Only RGB is exported, so alpha
	 * stays as it was. Like the vignette, they are held to `filterMaxPixels`.
	 *
	 * @param \Closure(int, int, int, \Closure():list<int>): ?list<int> $map
	 */
	private function applyRows(\Imagick $frame, \Closure $map): void
	{
		$width  = $frame->getImageWidth();
		$height = $frame->getImageHeight();

		for ($y = 0; $y < $height; $y++) {
			$read = static fn (): array => $frame->exportImagePixels(0, $y, $width, 1, 'RGB', \Imagick::PIXEL_CHAR);
			$out  = $map($y, $width, $height, $read);
			if ($out !== null) {
				$frame->importImagePixels(0, $y, $width, 1, 'RGB', \Imagick::PIXEL_CHAR, $out);
			}
		}
	}

	private function applyMatrix(\Imagick $frame, ColorMatrix $matrix): void
	{
		[$r, $g, $b] = $matrix->rows();

		// Columns: R, G, B, K, A, offset. Alpha row and column are identity.
		$kernel = [
			[$r[0], $r[1], $r[2], 0.0, 0.0, $r[3]],
			[$g[0], $g[1], $g[2], 0.0, 0.0, $g[3]],
			[$b[0], $b[1], $b[2], 0.0, 0.0, $b[3]],
			[0.0, 0.0, 0.0, 1.0, 0.0, 0.0],
			[0.0, 0.0, 0.0, 0.0, 1.0, 0.0],
			[0.0, 0.0, 0.0, 0.0, 0.0, 1.0],
		];

		// The extension takes the matrix as a flat row-major list of 36 numbers.
		$flat = array_merge(...$kernel);

		$frame->colorMatrixImage($flat);
		// HDRI builds of ImageMagick do not clamp, so out-of-range values would survive.
		$frame->clampImage();
	}
}
