<?php

namespace TotalCMS\Utils\Faker;

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
		self::$dir   = sys_get_temp_dir() . '/faker-images';
	}

	public static function imageUrl(int $width = 640, int $height = 480): string
	{
		return FakerPicsum::picsumUrl($width, $height);
	}

	public static function image(int $width = 640, int $height = 480): string
	{
		return FakerPicsum::picsum(self::$dir, $width, $height, false, 0);
	}

	public static function imageBlur(int $width = 640, int $height = 480, int $blur = 10): string
	{
		return FakerPicsum::picsum(self::$dir, $width, $height, false, $blur);
	}

	public static function imageBW(int $width = 640, int $height = 480): string
	{
		return FakerPicsum::picsum(self::$dir, $width, $height, true, 0);
	}

	public static function imageBWBlur(int $width = 640, int $height = 480, int $blur = 10): string
	{
		return FakerPicsum::picsum(self::$dir, $width, $height, true, $blur);
	}

	public static function imageText(int $width = 640, int $height = 480, string $bgColor = 'f8f8f8', int $textSize = 200, ?string $textColor = null, ?string $text = null): string
	{
		return FakerImageGD::imageText(self::$dir, $width, $height, $text, $textSize, $textColor, $bgColor);
	}

	public static function imageShapes(int $width = 640, int $height = 480, string $bgColor = 'f8f8f8'): string
	{
		return FakerImageGD::imageShapes(self::$dir, $width, $height, $bgColor);
	}

	/** @return array<string> */
	public static function tags(int $min = 0, int $max = 5): array
	{
		return array_values(array_unique((array)Lorem::words(self::numberBetween($min, $max), false)));
	}
}
