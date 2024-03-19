<?php

namespace TotalCMS\Utils;

use Faker\Provider\Base;
use Faker\Provider\Color;
use Faker\Provider\Lorem;

/**
 * Provider for the Faker generator.
 */
class FakerImageGD extends Base
{
    private static function hex2rgb(string $hex): array
    {
        $rgb = str_split(ltrim($hex, '#'), 2);
        $rgb = array_map('intval', array_map('hexdec', $rgb));

        return $rgb;
    }

    /**
     * Generate a new image to disk and return its location
     * Requires gd (default in most PHP setup).
     *
     * @param string $dir Path of the generated file, if null will use the system temp dir
     * @param int $width Width of the picture in pixels
     * @param int $height Height of the picture in pixels
     * @param string $text Text to generate on the picture, default no text, if true given will output width and height
     * @param int $textSize
     * @param string $textColor Text color in hexadecimal format, default to white
     * @param string $bgColor Background color in hexadecimal format (eg. #7f7f7f), default to black
     *
     * @example '/path/to/dir/13b73edae8443990be1aa8f1a483bc27.jpg'
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public static function imageGD($dir = null, $width = 640, $height = 480, $text = null, $textSize = 100, $textColor = null, $bgColor = 'f8f8f8'): string
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

        $fontFile = __DIR__ . '/FakerImageGD.ttf'; // Roboto Regular
        $textBox  = imagettfbbox($textSize, 0, $fontFile, $text);

        if (!is_array($textBox)) {
            throw new \RuntimeException('Failed to create text bounding box.');
        }

        $minBuffer = 50;
        $xBuffer   = $width - $textBox[2] - $textBox[0];
        $yBufffer  = $height - $textBox[1] - $textBox[7];

        // decrease the default font size until it fits nicely within the image
        while (($textSize > 1) && (($xBuffer < $minBuffer) || ($yBufffer < $minBuffer))) {
            $textSize--;
            $textBox = imagettfbbox($textSize, 0, $fontFile, $text);

            if (!is_array($textBox)) {
                throw new \RuntimeException('Failed to create text bounding box.');
            }

            $xBuffer  = $width - $textBox[2] - $textBox[0];
            $yBufffer = $height - $textBox[1] - $textBox[7];
        }

        $xCenter = intval(($width / 2) - (($textBox[2] - $textBox[0]) / 2));
        $yCenter = intval(($height / 2) + (($textBox[1] - $textBox[7]) / 2));

        imagettftext($image, $textSize, 0, $xCenter, $yCenter, $textColor, $fontFile, $text);

        imagepng($image, $filepath);
        imagedestroy($image);

        if (file_exists($filepath) === false) {
            throw new \RuntimeException('Failed to save image to disk.');
        }

        return $filepath;
    }
}
