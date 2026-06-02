<?php

namespace TotalCMS\Domain\Media\Service;

use ColorThief\ColorThief;

class ImagePaletteGenerator
{
	/**
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 * @SuppressWarnings("PHPMD.ErrorControlOperator")
	 *
	 * @throws \RuntimeException
	 *
	 * @return array<string>
	 */
	public static function getPalette(string $imagepath): array
	{
		// Early return if required extensions are not loaded
		if (!extension_loaded('imagick') && !extension_loaded('gd')) {
			throw new \RuntimeException('Image processing extensions not loaded (imagick or gd required)');
		}

		// Validate file exists and is readable
		if (!file_exists($imagepath)) {
			throw new \RuntimeException("Image file does not exist: {$imagepath}");
		}

		if (!is_readable($imagepath)) {
			throw new \RuntimeException("Image file is not readable: {$imagepath}");
		}

		// Check if file is actually an image
		$imageInfo = @getimagesize($imagepath);
		if ($imageInfo === false) {
			throw new \RuntimeException("File is not a valid image: {$imagepath}");
		}

		// Skip if image has no dimensions (blank image)
		if ($imageInfo[0] === 0 || $imageInfo[1] === 0) {
			throw new \RuntimeException("Image has invalid dimensions ({$imageInfo[0]}x{$imageInfo[1]}): {$imagepath}");
		}

		// Guard against a fatal out-of-memory while decoding the full bitmap.
		// ColorThief decodes the entire image; under GD that allocates the whole
		// truecolor canvas in PHP's memory budget, so a large image (e.g. a
		// 7500x5001 JPEG) can exhaust memory_limit and kill the worker with an
		// UNCATCHABLE fatal — which silently leaves the object's image metadata
		// empty. ColorThief uses Imagick when it's available (bounded
		// differently), so the guard only applies to GD-only installs. Throwing
		// RuntimeException here routes the large image into the caller's graceful
		// "skip palette, continue the save" path instead of OOM-killing the request.
		if (!extension_loaded('imagick') && extension_loaded('gd')) {
			$limit = self::memoryLimitBytes();
			if ($limit > 0) {
				$bits      = (int)($imageInfo['bits'] ?? 8);
				$channels  = (int)($imageInfo['channels'] ?? 3);
				$estimate  = (int)(((int)$imageInfo[0] * (int)$imageInfo[1] * $bits / 8 * $channels + 65536) * 1.8);
				$available = $limit - memory_get_usage(true);
				if ($estimate > $available) {
					throw new \RuntimeException(sprintf(
						'Image too large to decode with GD for palette generation (~%dMB needed, %dMB available): %s',
						intdiv($estimate, 1048576),
						intdiv(max(0, $available), 1048576),
						$imagepath,
					));
				}
			}
		}

		// Getting the top 15 colors from the image then reduce to top 5
		// This produces the best results after a lot of testing
		try {
			/** @var ?array<string> $palette */
			$palette = ColorThief::getPalette($imagepath, 15, 10, null, 'hex');
		} catch (\Throwable $e) {
			// Wrap any ColorThief errors in our exception
			$error = "ColorThief failed to generate palette for {$imagepath}: " . $e->getMessage();
			throw new \RuntimeException($error, 0, $e);
		}

		if (is_null($palette) || count($palette) === 0) {
			throw new \RuntimeException("ColorThief returned empty palette for: {$imagepath}");
		}

		return array_slice($palette, 0, 5);
	}

	/**
	 * Parse PHP's `memory_limit` into bytes. Returns -1 when unlimited or unset,
	 * signalling that no decode guard should be applied.
	 */
	private static function memoryLimitBytes(): int
	{
		$limit = trim((string)ini_get('memory_limit'));
		if ($limit === '' || $limit === '-1') {
			return -1;
		}

		$unit  = strtolower($limit[strlen($limit) - 1]);
		$value = (int)$limit;

		return match ($unit) {
			'g'     => $value * 1024 * 1024 * 1024,
			'm'     => $value * 1024 * 1024,
			'k'     => $value * 1024,
			default => $value,
		};
	}
}
