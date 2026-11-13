<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use GdImage;
use Imagick;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Applies `filtamt`: each color channel moves from the original toward the
 * filtered image by the given share, frame by frame, and every pixel keeps
 * the original's alpha. Intervention's place() with an opacity does neither
 * (on GD it is imagecopymerge, which drops alpha, and it places one frame).
 */
final class StrengthBlend
{
	/**
	 * Blend $filtered into $original at $amount (1-99) and return $original.
	 * When a filter changed the size or the frame count there is nothing to
	 * blend against, so the filtered image is returned as it is.
	 */
	public static function apply(ImageInterface $original, ImageInterface $filtered, int $amount): ImageInterface
	{
		if (
			$original->width() !== $filtered->width()
			|| $original->height() !== $filtered->height()
			|| count($original) !== count($filtered)
		) {
			return $filtered;
		}

		$source = $original->core()->native();
		$target = $filtered->core()->native();
		if ($source instanceof Imagick && $target instanceof Imagick) {
			self::imagick($source, $target, $amount);

			return $original;
		}

		$share = $amount / 100;
		foreach (range(0, count($original) - 1) as $index) {
			$base = $original->core()->frame($index)->native();
			$over = $filtered->core()->frame($index)->native();
			if ($base instanceof GdImage && $over instanceof GdImage) {
				self::gd($base, $over, $share);
			}
		}

		return $original;
	}

	private static function gd(GdImage $base, GdImage $over, float $share): void
	{
		foreach ([$base, $over] as $gd) {
			if (!imageistruecolor($gd)) {
				imagepalettetotruecolor($gd);
			}
		}
		imagealphablending($base, false);
		imagesavealpha($base, true);

		$width  = imagesx($base);
		$height = imagesy($base);
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$o = imagecolorat($base, $x, $y);
				$f = imagecolorat($over, $x, $y);

				$r = ($o >> 16) & 0xFF;
				$g = ($o >> 8) & 0xFF;
				$b = $o & 0xFF;
				$r = (int) round($r + ((($f >> 16) & 0xFF) - $r) * $share);
				$g = (int) round($g + ((($f >> 8) & 0xFF) - $g) * $share);
				$b = (int) round($b + (($f & 0xFF) - $b) * $share);

				imagesetpixel($base, $x, $y, ($o & 0x7F000000) | $r << 16 | $g << 8 | $b);
			}
		}
	}

	/**
	 * Blend compose: with equal alpha on both sides it is an exact per-channel
	 * mix and alpha is unchanged. A filter may still have touched alpha, so the
	 * original's alpha is copied back over the result.
	 */
	private static function imagick(Imagick $base, Imagick $over, int $amount): void
	{
		$frames = $base->getNumberImages();
		for ($index = 0; $index < $frames; $index++) {
			$base->setIteratorIndex($index);
			$over->setIteratorIndex($index);

			if ($base->getImageColorspace() !== $over->getImageColorspace()) {
				$base->transformImageColorspace($over->getImageColorspace());
			}
			$alpha = $base->getImageAlphaChannel() ? $base->getImage() : null;

			$base->setImageArtifact('compose:args', (string) $amount);
			$base->compositeImage($over, Imagick::COMPOSITE_BLEND, 0, 0);
			// Later composites (the marks) must not pick the setting up.
			$base->deleteImageArtifact('compose:args');

			if ($alpha instanceof Imagick) {
				$base->compositeImage($alpha, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
				$alpha->clear();
			}
		}
		$base->setIteratorIndex(0);
	}
}
