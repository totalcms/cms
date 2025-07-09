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

	public static function imageText(int $width = 640, int $height = 480, int $textSize = 200, string $bgColor = 'f8f8f8', ?string $textColor = null, ?string $text = null): string
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
	public static function galleryText(int $count = 3, int $width = 640, int $height = 480, int $textSize = 200, string $bgColor = 'f8f8f8', ?string $textColor = null, ?string $text = null): array
	{
		$images = [];
		for ($i = 0; $i < $count; $i++) {
			$images[] = self::imageText($width, $height, $textSize, $bgColor, $textColor, $text);
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
	 *
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

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 */
	public static function styledtext(int $minParagraphs = 3, int $maxParagraphs = 6, bool $includeHeadings = false, bool $includeLists = true): string
	{
		$content        = [];
		$paragraphCount = self::numberBetween($minParagraphs, $maxParagraphs);

		// Add optional heading at the start
		if ($includeHeadings && self::randomFloat(1, 0, 1) < 0.7) {
			$level     = self::numberBetween(2, 4);
			$content[] = '<h' . $level . '>' . Lorem::sentence(self::numberBetween(3, 8)) . '</h' . $level . '>';
		}

		for ($i = 0; $i < $paragraphCount; $i++) {
			// Randomly decide what type of content to add
			$rand = self::numberBetween(1, 10);

			if (!$includeLists || $rand <= 7) {
				// Regular paragraph with possible inline styling
				$content[] = self::generateStyledParagraph();
			} elseif ($rand == 8) {
				// Unordered list
				$content[] = self::generateList('ul');
			} elseif ($rand == 9) {
				// Ordered list
				$content[] = self::generateList('ol');
			} else {
				// Another heading if enabled
				if ($includeHeadings && $i > 0) {
					$level     = self::numberBetween(3, 5);
					$content[] = '<h' . $level . '>' . Lorem::sentence(self::numberBetween(3, 6)) . '</h' . $level . '>';
				} else {
					$content[] = self::generateStyledParagraph();
				}
			}
		}

		return implode("\n\n", $content);
	}

	private static function generateStyledParagraph(): string
	{
		$sentences = self::numberBetween(3, 8);
		$paragraph = [];

		for ($i = 0; $i < $sentences; $i++) {
			$sentence = Lorem::sentence(self::numberBetween(5, 15));

			// Randomly add inline styling
			$styleChance = self::numberBetween(1, 10);
			if ($styleChance == 1) {
				// Add a link
				$words             = explode(' ', $sentence);
				$linkStart         = self::numberBetween(0, max(0, count($words) - 3));
				$linkLength        = self::numberBetween(1, min(3, count($words) - $linkStart));
				$linkText          = implode(' ', array_slice($words, $linkStart, $linkLength));
				$linkUrl           = '#' . Lorem::word();
				$words[$linkStart] = '<a href="' . $linkUrl . '">' . $linkText;
				for ($j = 1; $j < $linkLength; $j++) {
					unset($words[$linkStart + $j]);
				}
				$words[$linkStart] .= '</a>';
				$sentence = implode(' ', array_values($words));
			} elseif ($styleChance == 2) {
				// Add strong emphasis
				$words             = explode(' ', $sentence);
				$strongPos         = self::numberBetween(0, max(0, count($words) - 2));
				$strongLength      = self::numberBetween(1, min(2, count($words) - $strongPos));
				$strongText        = implode(' ', array_slice($words, $strongPos, $strongLength));
				$words[$strongPos] = '<strong>' . $strongText . '</strong>';
				for ($j = 1; $j < $strongLength; $j++) {
					unset($words[$strongPos + $j]);
				}
				$sentence = implode(' ', array_values($words));
			} elseif ($styleChance == 3) {
				// Add italic emphasis
				$words         = explode(' ', $sentence);
				$emPos         = self::numberBetween(0, max(0, count($words) - 2));
				$emLength      = self::numberBetween(1, min(2, count($words) - $emPos));
				$emText        = implode(' ', array_slice($words, $emPos, $emLength));
				$words[$emPos] = '<em>' . $emText . '</em>';
				for ($j = 1; $j < $emLength; $j++) {
					unset($words[$emPos + $j]);
				}
				$sentence = implode(' ', array_values($words));
			}

			$paragraph[] = $sentence;
		}

		return '<p>' . implode(' ', $paragraph) . '</p>';
	}

	private static function generateList(string $type): string
	{
		$items = self::numberBetween(3, 8);
		$list  = '<' . $type . '>';

		for ($i = 0; $i < $items; $i++) {
			$itemText = Lorem::sentence(self::numberBetween(3, 10));

			// Sometimes add emphasis to list items
			if (self::randomFloat(1, 0, 1) < 0.2) {
				$words       = explode(' ', $itemText);
				$pos         = self::numberBetween(0, max(0, count($words) - 2));
				$words[$pos] = self::randomFloat(1, 0, 1) < 0.5 ? '<strong>' . $words[$pos] . '</strong>' : '<em>' . $words[$pos] . '</em>';
				$itemText    = implode(' ', $words);
			}

			$list .= "\n\t<li>" . $itemText . '</li>';
		}

		$list .= "\n</" . $type . '>';

		return $list;
	}
}
