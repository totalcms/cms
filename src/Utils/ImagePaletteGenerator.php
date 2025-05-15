<?php

namespace TotalCMS\Utils;

use ColorThief\ColorThief;

class ImagePaletteGenerator
{
	/** @return array<string> */
	public static function getPalette(string $imagepath): array
	{
		if (!extension_loaded('imagick') && !extension_loaded('gd')) {
			return [];
		}
		// Getting the top 15 colors from the image then reduce to top 5
		// This produces the best results after a lot of testing
		/** @var ?array<string> $palette */
		try {
			$palette = ColorThief::getPalette($imagepath, 15, 10, null, 'hex');
		} catch (\Exception $e) {
			return [];
		}

		if (is_null($palette) || count($palette) === 0) {
			return [];
		}
		$palette = array_slice($palette, 0, 5);

		return $palette;
	}
}
