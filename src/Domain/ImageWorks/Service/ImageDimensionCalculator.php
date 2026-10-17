<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

/**
 * Calculate processed image dimensions after ImageWorks/Glide transformations.
 */
class ImageDimensionCalculator
{
	/**
	 * Calculate the dimensions of an image after ImageWorks processing.
	 *
	 * @param int $originalWidth Original image width
	 * @param int $originalHeight Original image height
	 * @param array<string,string|int> $params ImageWorks parameters (w, h, fit, etc.)
	 *
	 * @return array{width: int, height: int} Processed dimensions
	 */
	public static function calculate(int $originalWidth, int $originalHeight, array $params): array
	{
		if ($originalWidth === 0 || $originalHeight === 0) {
			return ['width' => $originalWidth, 'height' => $originalHeight];
		}

		['width' => $originalWidth, 'height' => $originalHeight] = self::sourceDimensions($originalWidth, $originalHeight, $params);

		$targetWidth  = isset($params['w']) ? (int)$params['w'] : null;
		$targetHeight = isset($params['h']) ? (int)$params['h'] : null;
		$fit          = $params['fit'] ?? null;

		// If no dimensions specified, return original
		if ($targetWidth === null && $targetHeight === null) {
			return ['width' => $originalWidth, 'height' => $originalHeight];
		}

		// Calculate aspect ratio
		$aspectRatio = $originalWidth / $originalHeight;

		// Glide NEVER upscales images - clamp target dimensions to original size
		if ($targetWidth !== null) {
			$targetWidth = min($targetWidth, $originalWidth);
		}
		if ($targetHeight !== null) {
			$targetHeight = min($targetHeight, $originalHeight);
		}

		// Handle different fit modes (matching Glide behavior)
		// https://glide.thephpleague.com/2.0/api/size/#fit-fit
		if ($fit === 'crop' || str_starts_with((string)$fit, 'crop-')) {
			// Crop modes: crop to target dimensions (but never larger than original)
			// crop, crop-top-left, crop-top, crop-top-right, crop-left, crop-center,
			// crop-right, crop-bottom-left, crop-bottom, crop-bottom-right, crop-focalpoint
			return [
				'width'  => $targetWidth ?? $originalWidth,
				'height' => $targetHeight ?? $originalHeight,
			];
		}

		if ($fit === 'stretch') {
			// Stretch: output target dimensions, distorts to fit (but never larger than original)
			return [
				'width'  => $targetWidth ?? $originalWidth,
				'height' => $targetHeight ?? $originalHeight,
			];
		}

		// Default behavior: contain/fill-max (maintain aspect ratio, no upscaling)
		// Also handles: contain, max, fill, fill-max
		if ($targetWidth !== null && $targetHeight !== null) {
			// Both dimensions specified - fit within bounds maintaining aspect ratio
			$widthRatio  = $targetWidth / $originalWidth;
			$heightRatio = $targetHeight / $originalHeight;
			$ratio       = min($widthRatio, $heightRatio);

			return [
				'width'  => (int)round($originalWidth * $ratio),
				'height' => (int)round($originalHeight * $ratio),
			];
		}

		if ($targetWidth !== null) {
			// Only width specified - maintain aspect ratio
			return [
				'width'  => $targetWidth,
				'height' => (int)round($targetWidth / $aspectRatio),
			];
		}

		// Only height specified — maintain aspect ratio. $targetHeight is
		// guaranteed non-null here: the "both null" branch returned at line 32,
		// the "both non-null" branch returned at line 69, and the "width-only"
		// branch returned just above — leaving "height-only" as the sole path
		// that reaches here.
		return [
			'width'  => (int)round($targetHeight * $aspectRatio),
			'height' => $targetHeight,
		];
	}

	/**
	 * The dimensions every later step starts from: the image itself, or the
	 * `crop` rect (`width,height,x,y`) when one is given — Glide's crop
	 * manipulator runs before its size step, so a candidate can never be
	 * wider than the rect. The rect is validated and clamped exactly as Glide
	 * does it: four numbers, a positive size, an offset inside the image, and
	 * a size cut down to what remains past the offset. Anything else is
	 * ignored, as Glide ignores it.
	 *
	 * @param array<string,string|int> $params
	 *
	 * @return array{width: int, height: int}
	 */
	public static function sourceDimensions(int $width, int $height, array $params): array
	{
		$crop = (string)($params['crop'] ?? '');
		if ($crop === '' || $width === 0 || $height === 0) {
			return ['width' => $width, 'height' => $height];
		}

		$rect = explode(',', $crop);
		if (count($rect) !== 4 || array_filter($rect, is_numeric(...)) !== $rect) {
			return ['width' => $width, 'height' => $height];
		}

		[$rectWidth, $rectHeight, $x, $y] = array_map(intval(...), $rect);
		if ($rectWidth <= 0 || $rectHeight <= 0 || $x < 0 || $y < 0 || $x >= $width || $y >= $height) {
			return ['width' => $width, 'height' => $height];
		}

		return [
			'width'  => min($rectWidth, $width - $x),
			'height' => min($rectHeight, $height - $y),
		];
	}

	/**
	 * Calculate dimensions from image data array.
	 *
	 * @param array<string,mixed> $image Image data with width/height keys
	 * @param array<string,string|int> $params ImageWorks parameters
	 *
	 * @return array{width: int, height: int} Processed dimensions
	 */
	public static function calculateFromImageData(array $image, array $params): array
	{
		$originalWidth  = (int)($image['width'] ?? 0);
		$originalHeight = (int)($image['height'] ?? 0);

		return self::calculate($originalWidth, $originalHeight, $params);
	}
}
