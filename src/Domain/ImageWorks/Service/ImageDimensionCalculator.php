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

		// Only height specified - maintain aspect ratio
		// PHPStan knows $targetHeight must be non-null here due to early return at line 30
		if ($targetHeight === null) {
			return ['width' => $originalWidth, 'height' => $originalHeight];
		}

		return [
			'width'  => (int)round($targetHeight * $aspectRatio),
			'height' => $targetHeight,
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
