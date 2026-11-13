<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use Closure;
use GdImage;
use Intervention\Image\Interfaces\ImageInterface;
use LogicException;

/**
 * GD renderer. Each run of consecutive linear steps is folded into one
 * ColorMatrix and applied in a single pass over the pixels; overlay and
 * gradient-map run as lookup-table passes, the vignette as a computed pass,
 * grain and leak as row passes through NonlinearSteps::rowFunction().
 * Every frame of an animation gets every step.
 */
final class GdRecipeRenderer implements RecipeRenderer
{
	public function apply(ImageInterface $image, array $steps, FilterContext $context): ImageInterface
	{
		if (!$image->core()->native() instanceof GdImage) {
			throw new LogicException('GdRecipeRenderer needs a GD image');
		}

		// Everything that can fail (a color the tables cannot read) is worked
		// out before the first pixel changes, so a failed filter leaves no trace.
		$passes = array_map(fn (ColorMatrix|FilterStep $run): Closure => $this->pass($run), FilterRuns::split($steps));

		foreach ($image as $frame) {
			$gd = $frame->native();
			if (!$gd instanceof GdImage) {
				continue;
			}
			if (!imageistruecolor($gd)) {
				imagepalettetotruecolor($gd);
			}
			imagealphablending($gd, false);
			imagesavealpha($gd, true);

			foreach ($passes as $pass) {
				$pass($gd);
			}
		}

		return $image;
	}

	/** @return Closure(GdImage):void */
	private function pass(ColorMatrix|FilterStep $run): Closure
	{
		if ($run instanceof ColorMatrix) {
			return fn (GdImage $gd) => $this->applyMatrix($gd, $run);
		}

		return match ($run->op) {
			'overlay'      => $this->tablesPass(NonlinearSteps::overlayTables($run), false),
			'gradient-map' => $this->tablesPass(NonlinearSteps::gradientTables($run), true),
			'vignette'     => $this->vignettePass($run),
			'grain',
			'leak'         => $this->rowsPass(NonlinearSteps::rowFunction($run)),
			default        => throw new LogicException(sprintf('Unknown step "%s"', $run->op)),
		};
	}

	/**
	 * @param array{list<int>,list<int>,list<int>} $tables
	 *
	 * @return Closure(GdImage):void
	 */
	private function tablesPass(array $tables, bool $byLuminance): Closure
	{
		return fn (GdImage $gd) => $this->applyTables($gd, $tables, $byLuminance);
	}

	/** @return Closure(GdImage):void */
	private function vignettePass(FilterStep $step): Closure
	{
		$strength = (float) $step->args[0];
		$color    = NonlinearSteps::rgb((string) ($step->args[1] ?? '000000'));

		return fn (GdImage $gd) => $this->applyVignette($gd, $strength, $color);
	}

	/**
	 * @param Closure(int, int, int, Closure():list<int>): ?list<int> $map
	 *
	 * @return Closure(GdImage):void
	 */
	private function rowsPass(Closure $map): Closure
	{
		return fn (GdImage $gd) => $this->applyRows($gd, $map);
	}

	/**
	 * Hands $map each row as flat R, G, B bytes and writes back what it returns,
	 * with each pixel's alpha as it was. Pixels the step left alone are not rewritten.
	 *
	 * @param Closure(int, int, int, Closure():list<int>): ?list<int> $map
	 */
	private function applyRows(GdImage $gd, Closure $map): void
	{
		$width  = imagesx($gd);
		$height = imagesy($gd);

		for ($y = 0; $y < $height; $y++) {
			$packed = [];
			$read   = static function () use ($gd, $y, $width, &$packed): array {
				$rgb = [];
				for ($x = 0; $x < $width; $x++) {
					$c        = imagecolorat($gd, $x, $y);
					$packed[] = $c;
					$rgb[]    = ($c >> 16) & 0xFF;
					$rgb[]    = ($c >> 8) & 0xFF;
					$rgb[]    = $c & 0xFF;
				}

				return $rgb;
			};

			$out = $map($y, $width, $height, $read);
			if ($out === null) {
				continue;
			}
			foreach ($packed as $x => $c) {
				$i = $x * 3;
				$n = ($c & 0x7F000000) | $out[$i] << 16 | $out[$i + 1] << 8 | $out[$i + 2];
				if ($n !== $c) {
					imagesetpixel($gd, $x, $y, $n);
				}
			}
		}
	}

	/**
	 * @param array{list<int>,list<int>,list<int>} $tables
	 */
	private function applyTables(GdImage $gd, array $tables, bool $byLuminance): void
	{
		[$rt, $gt, $bt] = $tables;
		$width          = imagesx($gd);
		$height         = imagesy($gd);

		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$c = imagecolorat($gd, $x, $y);
				$r = ($c >> 16) & 0xFF;
				$g = ($c >> 8) & 0xFF;
				$b = $c & 0xFF;
				if ($byLuminance) {
					$l = (int) round(0.2126 * $r + 0.7152 * $g + 0.0722 * $b);
					$r = $g = $b = $l;
				}
				imagesetpixel($gd, $x, $y, ($c & 0x7F000000) | $rt[$r] << 16 | $gt[$g] << 8 | $bt[$b]);
			}
		}
	}

	/** @param array{int,int,int} $color */
	private function applyVignette(GdImage $gd, float $strength, array $color): void
	{
		[$vr, $vg, $vb] = $color;
		$width          = imagesx($gd);
		$height         = imagesy($gd);

		for ($y = 0; $y < $height; $y++) {
			$row = NonlinearSteps::vignetteRow($y, $width, $height, $strength);
			for ($x = 0; $x < $width; $x++) {
				$k = $row[$x];
				if ($k <= 0.0) {
					continue;
				}
				$c  = imagecolorat($gd, $x, $y);
				$nr = (int) round((($c >> 16) & 0xFF) * (1 - $k) + $vr * $k);
				$ng = (int) round((($c >> 8) & 0xFF) * (1 - $k) + $vg * $k);
				$nb = (int) round(($c & 0xFF) * (1 - $k) + $vb * $k);
				imagesetpixel($gd, $x, $y, ($c & 0x7F000000) | $nr << 16 | $ng << 8 | $nb);
			}
		}
	}

	private function applyMatrix(GdImage $gd, ColorMatrix $matrix): void
	{
		[$r0, $r1, $r2, $r3] = $matrix->rows()[0];
		[$g0, $g1, $g2, $g3] = $matrix->rows()[1];
		[$b0, $b1, $b2, $b3] = $matrix->rows()[2];
		$r3 *= 255;
		$g3 *= 255;
		$b3 *= 255;

		$width  = imagesx($gd);
		$height = imagesy($gd);
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$c = imagecolorat($gd, $x, $y);
				$r = ($c >> 16) & 0xFF;
				$g = ($c >> 8) & 0xFF;
				$b = $c & 0xFF;

				$nr = (int) round($r0 * $r + $r1 * $g + $r2 * $b + $r3);
				$ng = (int) round($g0 * $r + $g1 * $g + $g2 * $b + $g3);
				$nb = (int) round($b0 * $r + $b1 * $g + $b2 * $b + $b3);

				imagesetpixel(
					$gd,
					$x,
					$y,
					($c & 0x7F000000)
					| ($nr < 0 ? 0 : ($nr > 255 ? 255 : $nr)) << 16
					| ($ng < 0 ? 0 : ($ng > 255 ? 255 : $ng)) << 8
					| ($nb < 0 ? 0 : ($nb > 255 ? 255 : $nb)),
				);
			}
		}
	}
}
