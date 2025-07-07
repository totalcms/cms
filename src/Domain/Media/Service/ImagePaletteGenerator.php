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

		$palette = array_slice($palette, 0, 5);

		return $palette;
	}
}
