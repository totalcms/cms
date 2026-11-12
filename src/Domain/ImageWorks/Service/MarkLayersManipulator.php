<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

use Intervention\Image\Interfaces\ImageInterface;
use League\Flysystem\FilesystemOperator;
use League\Glide\Manipulators\BaseManipulator;
use League\Glide\Manipulators\Size;
use League\Glide\Manipulators\Watermark;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Data\TextWatermarkStyle;

/**
 * Draws every mark layer over the image in one pass, in list order. Takes
 * the place of Glide's own Watermark manipulator, which draws one.
 *
 * Its `marks` param is the list WatermarkFactory::prepare() builds: a layer
 * with `src` is an image file under the data root (a logo, a record's image,
 * a tiled pattern), a layer with `marktext` is text drawn here with
 * Intervention. Either is sized and placed with Glide's watermark rules, so
 * `markpos`, `markpad`, `15w` and `markalpha` mean what they always have.
 * Each layer's file version is in the list, so Glide's cache key changes
 * when a file does.
 */
final class MarkLayersManipulator extends BaseManipulator
{
	/** A text mark drawn wider than the image is scaled to this. */
	private const OVERSIZE_TEXT_WIDTH = '90w';

	public function __construct(
		private readonly FilesystemOperator $files,
		private readonly TextWatermarkFactory $textMarks,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/** @return list<string> */
	public function getApiParams(): array
	{
		return ['marks', 'dpr'];
	}

	public function run(ImageInterface $image): ImageInterface
	{
		$layers = $this->getParam('marks');
		if (!is_array($layers)) {
			return $image;
		}

		foreach ($layers as $layer) {
			if (!is_array($layer)) {
				continue;
			}

			try {
				$image = $this->drawLayer($image, $layer);
			} catch (\Throwable $e) {
				// One broken layer (a missing font, an unreadable file) must not
				// cost the whole image.
				$this->logger?->warning('ImageWorks mark layer skipped', [
					'layer' => $layer['src'] ?? $layer['marktext'] ?? '',
					'error' => $e->getMessage(),
				]);
			}
		}

		return $image;
	}

	/** @param array<array-key,mixed> $layer */
	private function drawLayer(ImageInterface $image, array $layer): ImageInterface
	{
		$mark = isset($layer['src']) ? $this->readMark($image, (string)$layer['src']) : $this->drawText($image, $layer);
		if (!$mark instanceof ImageInterface) {
			return $image;
		}

		if (($layer['fitwidth'] ?? false) && $mark->width() > $image->width()) {
			$layer['markw'] = self::OVERSIZE_TEXT_WIDTH;
		}

		$rules = new Watermark();
		$rules->setParams(array_filter(
			['dpr' => $this->getParam('dpr')] + $layer,
			fn (mixed $value): bool => is_scalar($value),
		));

		$markx   = $rules->getDimension($image, 'markx');
		$marky   = $rules->getDimension($image, 'marky');
		$markpad = $rules->getDimension($image, 'markpad');
		if ($markpad !== null) {
			$markx = $marky = $markpad;
		}

		$size = new Size();
		$size->setParams([
			'w'   => $rules->getDimension($image, 'markw'),
			'h'   => $rules->getDimension($image, 'markh'),
			// A record's image may be cut around its focal point (crop-50-30),
			// which Glide's watermark fits do not include.
			'fit' => isset($layer['focalfit']) ? (string)$layer['focalfit'] : $rules->getFit(),
		]);
		$mark = $size->run($mark);

		if (($layer['markshape'] ?? '') === 'circle') {
			$mark = $this->circle($mark);
		}

		return $image->place($mark, $rules->getPosition(), (int)$markx, (int)$marky, $rules->getAlpha());
	}

	private function readMark(ImageInterface $image, string $path): ?ImageInterface
	{
		if (!$this->files->fileExists($path)) {
			return null;
		}

		$mark = $image->driver()->handleInput($this->files->read($path));

		return $mark instanceof ImageInterface ? $mark : null;
	}

	/** @param array<array-key,mixed> $layer */
	private function drawText(ImageInterface $image, array $layer): ImageInterface
	{
		return $this->textMarks->draw(new TextWatermarkStyle(array_filter(
			$layer,
			fn (mixed $value, int|string $key): bool => str_starts_with((string)$key, 'marktext') && is_scalar($value),
			ARRAY_FILTER_USE_BOTH,
		)), $image->driver());
	}

	/**
	 * The mark cut to the largest circle it holds, with a one-pixel soft
	 * edge. Intervention 3 has no mask, so each driver does it natively.
	 */
	private function circle(ImageInterface $mark): ImageInterface
	{
		$native = $mark->core()->native();
		$width  = $mark->width();
		$height = $mark->height();

		if ($native instanceof \Imagick) {
			$shape = new \Imagick();
			$shape->newImage($width, $height, new \ImagickPixel('transparent'));
			$draw = new \ImagickDraw();
			$draw->setFillColor(new \ImagickPixel('white'));
			$draw->circle(($width - 1) / 2, ($height - 1) / 2, ($width - 1) / 2, ($height - 1) / 2 - (min($width, $height) - 1) / 2);
			$shape->drawImage($draw);
			$native->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
			$native->compositeImage($shape, \Imagick::COMPOSITE_DSTIN, 0, 0);

			return $mark;
		}

		if (!$native instanceof \GdImage) {
			return $mark;
		}

		$gd = $native;
		if (!imageistruecolor($gd)) {
			imagepalettetotruecolor($gd);
		}

		imagealphablending($gd, false);
		imagesavealpha($gd, true);
		$centerX = ($width - 1) / 2;
		$centerY = ($height - 1) / 2;
		$radius  = min($width, $height) / 2;

		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$outside = sqrt((($x - $centerX) ** 2) + (($y - $centerY) ** 2)) - $radius + 0.5;
				if ($outside <= 0) {
					continue;
				}

				$rgba  = imagecolorat($gd, $x, $y);
				$alpha = ($rgba >> 24) & 0x7F;
				// GD alpha: 0 opaque to 127 clear.
				$alpha = $outside >= 1 ? 127 : (int)round($alpha + ((127 - $alpha) * $outside));
				imagesetpixel($gd, $x, $y, ($alpha << 24) | ($rgba & 0xFFFFFF));
			}
		}

		return $mark;
	}
}
