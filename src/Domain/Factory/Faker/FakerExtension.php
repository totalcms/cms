<?php

namespace TotalCMS\Domain\Factory\Faker;

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
	public static function gallery(int $count = 3, int $width = 640, int $height = 480): array
	{
		$images = [];
		for ($i = 0; $i < $count; $i++) {
			$images[] = self::image($width, $height);
		}
		return $images;
	}

	/** @return array<string> */
	public static function galleryBlur(int $count = 3, int $width = 640, int $height = 480, int $blur = 10): array
	{
		$images = [];
		for ($i = 0; $i < $count; $i++) {
			$images[] = self::imageBlur($width, $height, $blur);
		}
		return $images;
	}

	/** @return array<string> */
	public static function galleryBW(int $count = 3, int $width = 640, int $height = 480): array
	{
		$images = [];
		for ($i = 0; $i < $count; $i++) {
			$images[] = self::imageBW($width, $height);
		}
		return $images;
	}

	/** @return array<string> */
	public static function galleryBWBlur(int $count = 3, int $width = 640, int $height = 480, int $blur = 10): array
	{
		$images = [];
		for ($i = 0; $i < $count; $i++) {
			$images[] = self::imageBWBlur($width, $height, $blur);
		}
		return $images;
	}

	/** @return array<string> */
	public static function galleryText(int $count = 3, int $width = 640, int $height = 480, string $bgColor = 'f8f8f8', int $textSize = 200, ?string $textColor = null, ?string $text = null): array
	{
		$images = [];
		for ($i = 0; $i < $count; $i++) {
			$images[] = self::imageText($width, $height, $bgColor, $textSize, $textColor, $text);
		}
		return $images;
	}

	/** @return array<string> */
	public static function galleryShapes(int $count = 3, int $width = 640, int $height = 480, string $bgColor = 'f8f8f8'): array
	{
		$images = [];
		for ($i = 0; $i < $count; $i++) {
			$images[] = self::imageShapes($width, $height, $bgColor);
		}
		return $images;
	}

	/**
	 * @param array<string> $choices
	 * @return array<string>
	 * */
	public static function tags(int $min = 0, int $max = 4, array $choices = []): array
	{
		shuffle($choices);
		$words = empty($choices) ?
			array_unique((array)Lorem::words(self::numberBetween($min, $max), false)) :
			array_slice($choices, 0, self::numberBetween($min, $max));

		return array_values($words);
	}
}
