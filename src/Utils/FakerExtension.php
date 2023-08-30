<?php

namespace TotalCMS\Utils;

use Faker\Generator as FakerGenerator;
use Faker\Provider\Base;
use Faker\Provider\Lorem;

/**
 * Provider for the Faker generator.
 */
class FakerExtension extends Base
{
    public static string $dir;

    public function __construct(FakerGenerator $faker)
    {
        parent::__construct($faker);
        self::$dir = sys_get_temp_dir() . '/faker-images';
    }

    public static function imageUrl($width = 640, $height = 480): string
    {
        return FakerPicsum::picsumUrl(self::$dir, $width, $height, false, false);
    }

    public static function image($width = 640, $height = 480): string
    {
        return FakerPicsum::picsum(self::$dir, $width, $height, false, false);
    }

    public static function imageBlur($width = 640, $height = 480): string
    {
        return FakerPicsum::picsum(self::$dir, $width, $height, false, true);
    }

    public static function imageBW($width = 640, $height = 480): string
    {
        return FakerPicsum::picsum(self::$dir, $width, $height, true, false);
    }

    public static function imageBWBlur($width = 640, $height = 480): string
    {
        return FakerPicsum::picsum(self::$dir, $width, $height, false, false);
    }

    public static function imageText($width = 640, $height = 480, $text = null, $textSize = 100, $textColor = null, $bgColor = 'f8f8f8'): string
    {
        return FakerImageGD::imageGD(self::$dir, $width, $height, $text, $textSize, $textColor, $bgColor);
    }

    public static function tags($min = 0, $max = 5): array
    {
        return Lorem::words(self::numberBetween($min, $max), false);
    }
}
