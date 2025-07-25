<?php

namespace TotalCMS\Domain\Factory\Faker;

use Faker\Provider\Base;
use Faker\Provider\Color;
use Faker\Provider\Lorem;

/**
 * Provider for the Faker generator.
 */
class FakerImageGD extends Base
{
	private const FONT_PATH = __DIR__ . '/../../../../resources/fonts/RobotoRegular.ttf';

	/** @return array<int<0,255>> */
	private static function hex2rgb(string $hex): array
	{
		$rgb = str_split(ltrim($hex, '#'), 2);
		$rgb = array_map('intval', array_map('hexdec', $rgb));
		$rgb = array_map(fn ($value) => max(0, min($value, 255)), $rgb);

		if (count($rgb) !== 3) {
			throw new \InvalidArgumentException('Invalid hex color value.');
		}

		return $rgb;
	}

	/**
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @param ?string $dir
	 * @param int $width
	 * @param int $height
	 * @param ?string $text
	 * @param int $textSize
	 * @param ?string $textColor
	 * @param string $bgColor
	 */
	public static function imageText(?string $dir = null, int $width = 640, int $height = 480, ?string $text = null, int $textSize = 200, ?string $textColor = null, string $bgColor = 'f8f8f8'): string
	{
		// Default to system temp dir
		$dir = $dir ?? sys_get_temp_dir();

		// Validate directory path
		if (!is_dir($dir) || !is_writable($dir)) {
			throw new \InvalidArgumentException(sprintf('Cannot write to directory "%s"', $dir));
		}

		$text      = empty($text) ? strtoupper(substr(Lorem::word(), 0, rand(1, 6))) : $text;
		$textColor = empty($textColor) ? Color::hexColor() : $textColor;

		// Generate a random filename.
		$filename = uniqid('imageText-', true) . '.png';
		$filepath = $dir . DIRECTORY_SEPARATOR . $filename;

		if (!function_exists('imagecreate')) {
			throw new \RuntimeException('GD is not available on this PHP installation. Impossible to generate image.');
		}

		if ($width <= 0 || $height <= 0) {
			throw new \InvalidArgumentException('Width and height must be greater than 0.');
		}
		$image = imagecreate($width, $height);

		if ($image === false) {
			throw new \RuntimeException('Failed to create image with GD.');
		}

		$bgColor = self::hex2rgb($bgColor);
		imagecolorallocate($image, ...$bgColor);

		$textColor = self::hex2rgb($textColor);
		$textColor = imagecolorallocate($image, ...$textColor);

		if ($textColor === false) {
			throw new \RuntimeException('Failed to allocate text color.');
		}

		if (function_exists('imageftbbox')) {
			$fontFile = self::FONT_PATH; // Roboto Regular
			$textBox  = imageftbbox($textSize, 0, $fontFile, $text);

			if (!is_array($textBox)) {
				throw new \RuntimeException('Failed to create text bounding box.');
			}

			$minBuffer = 50;
			$xBuffer   = $width - $textBox[2] - $textBox[0];
			$yBufffer  = $height - $textBox[1] - $textBox[7];

			// decrease the default font size until it fits nicely within the image
			while (($textSize > 1) && (($xBuffer < $minBuffer) || ($yBufffer < $minBuffer))) {
				$textSize--;
				$textBox = imageftbbox($textSize, 0, $fontFile, $text);

				if (!is_array($textBox)) {
					throw new \RuntimeException('Failed to create text bounding box.');
				}

				$xBuffer  = $width - $textBox[2] - $textBox[0];
				$yBufffer = $height - $textBox[1] - $textBox[7];
			}

			$xCenter = intval(($width / 2) - (($textBox[2] - $textBox[0]) / 2));
			$yCenter = intval(($height / 2) + (($textBox[1] - $textBox[7]) / 2));

			imagettftext($image, $textSize, 0, $xCenter, $yCenter, $textColor, $fontFile, $text);
		} else {
			$xCenter = intval(($width / 2) - (strlen($text) * 10));
			$yCenter = intval($height / 2);

			imagestring($image, 5, $xCenter, $yCenter, $text, $textColor);
		}

		imagepng($image, $filepath);
		imagedestroy($image);

		if (file_exists($filepath) === false) {
			throw new \RuntimeException('Failed to save image to disk.');
		}

		return $filepath;
	}

	/**
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 *
	 * @param ?string $dir
	 * @param int $width
	 * @param int $height
	 * @param string $bgColor
	 */
	public static function imageShapes(?string $dir = null, int $width = 640, int $height = 480, string $bgColor = 'f8f8f8'): string
	{
		// Default to system temp dir
		$dir = $dir ?? sys_get_temp_dir();

		// Validate directory path
		if (!is_dir($dir) || !is_writable($dir)) {
			throw new \InvalidArgumentException(sprintf('Cannot write to directory "%s"', $dir));
		}

		// Generate a random filename.
		$filename = uniqid('imageShape-', true) . '.png';
		$filepath = $dir . DIRECTORY_SEPARATOR . $filename;

		if (!function_exists('imagecreate')) {
			throw new \RuntimeException('GD is not available on this PHP installation. Impossible to generate image.');
		}

		if ($width <= 0 || $height <= 0) {
			throw new \InvalidArgumentException('Width and height must be greater than 0.');
		}
		$image = imagecreate($width, $height);

		if ($image === false) {
			throw new \RuntimeException('Failed to create image with GD.');
		}

		$bgColor = self::hex2rgb($bgColor);
		imagecolorallocate($image, ...$bgColor);

		$rectCount = rand(0, 1);
		for ($i = 0; $i < $rectCount; $i++) {
			// Draw a random rectangle with random color
			$grayscale = rand(0, 255);
			$color     = imagecolorallocate($image, $grayscale, $grayscale, $grayscale);
			$x1        = rand(0, intval($width / 2));                                             // Random start x
			$y1        = rand(0, intval($height / 2));                                            // Random start y
			$x2        = $x1 + rand(intval($width / 5), intval($width / 3));                              // Random end x
			$y2        = $y1 + rand(intval($height / 5), intval($height / 3));                            // Random end y

			if ($color === false) {
				$color = 1;
			}
			imagefilledrectangle($image, $x1, $y1, $x2, $y2, $color);
		}

		$circleCount = rand(1, 3);
		for ($i = 0; $i < $circleCount; $i++) {
			// Draw a random rectangle with random color
			$grayscale = rand(0, 255);
			$color     = imagecolorallocate($image, $grayscale, $grayscale, $grayscale);
			$cr        = rand(intval($width / 15), intval($width / 5));                                   // Radius
			$cx        = rand(0, $width);                                                 // Center x
			$cy        = rand(0, $height);                                                // Center y
			// $cx          = rand($width / 4, 3 * $width / 4); // Center x
			// $cy          = rand($height / 4, 3 * $height / 4); // Center y
			if ($color === false) {
				$color = 1;
			}
			imagefilledellipse($image, $cx, $cy, 2 * $cr, 2 * $cr, $color);
		}

		imagepng($image, $filepath);
		imagedestroy($image);

		if (file_exists($filepath) === false) {
			throw new \RuntimeException('Failed to save image to disk.');
		}

		return $filepath;
	}
}
