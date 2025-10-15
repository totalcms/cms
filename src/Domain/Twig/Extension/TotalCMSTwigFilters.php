<?php

namespace TotalCMS\Domain\Twig\Extension;

use Cake\Chronos\Chronos;
use PHP_CodeSniffer\Generators\HTML;
use TotalCMS\Domain\Collection\Utilities\CollectionRefiner;
use TotalCMS\Domain\Collection\Utilities\CollectionSorter;
use TotalCMS\Domain\Property\Data\ColorData;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Support\Config;
use Twig\TwigFilter;

/**
 * Twig Functions for Total CMS.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 */
class TotalCMSTwigFilters
{
	/** @var array<string> */
	public static array $phpFunctions = [
		'basename',
		'dirname',
		'rtrim',
		'ltrim',
		'trim',
		'ucwords',
		'lcfirst',
		'html_entity_decode',
		'urldecode',
		'urlencode',
		'rawurlencode',
		'ceil',
		'floor',
		'count',
	];

	/** @var array<string> */
	public static array $customFunctions = [
		'charcount',
		'wordcount',
		'readtime',
		'digitsOnly',
		'formatPhone',
		'humanize',
		'titleize',
		'truncate',
		'truncateWords',
		'sortBy',
		'ksort',
		'krsort',
		'shuffle',
		'print_r',
		'var_dump',
		'typeof',
		'string',
		'int',
		'float',
		'bool',
		'array',
		'hex',
		'rgb',
		'hsl',
		'oklch',
		'hexToColor',
		'color',
		'colour',
		'lightness',
		'chroma',
		'hue',
		'adjustColor',
		'htmlencode',
		'obfuscate',
		'deobfuscate',
		'encrypt',
		'decrypt',
		'filterCollection',
		'sortCollection',
		'paginate',
		'svgToSymbol',
		'markdown',
		'dateRelative',
		'dateFormat',
		'dateAdd',
		'dateSubtract',
		'dateDiff',
		'dateStartOf',
		'dateEndOf',
		'dateIsWeekend',
		'dateIsWeekday',
		'dateIsPast',
		'dateIsFuture',
		'dateIsToday',
		'price',
		'mailto',
	];

	/** @return array<TwigFilter> */
	public static function getFilters(): array
	{
		$twigFunctions = [];

		foreach (self::$customFunctions as $function) {
			$twigFunctions[] = new TwigFilter($function, [self::class, $function], ['is_safe' => ['html']]);
		}

		foreach (self::$phpFunctions as $function) {
			// @phpstan-ignore-next-line
			$twigFunctions[] = new TwigFilter($function, $function);
		}

		return $twigFunctions;
	}

	// -------------------------
	// Text Manipulation
	// -------------------------
	public static function digitsOnly(string $text): string
	{
		return (string)preg_replace('/\D/', '', $text);
	}

	public static function humanize(string $slug, string $sep = '-'): string
	{
		return ucfirst(str_replace($sep, ' ', $slug));
	}

	public static function titleize(string $slug, string $sep = '-'): string
	{
		return ucwords(str_replace($sep, ' ', $slug));
	}

	public static function htmlencode(string $string): string
	{
		return HTMLUtils::htmlencode($string);
	}

	public static function obfuscate(string $string): string
	{
		return Cipher::obfuscate($string);
	}

	public static function deobfuscate(string $string): string
	{
		return Cipher::deobfuscate($string);
	}

	public static function encrypt(string $string): string
	{
		return Cipher::encrypt($string);
	}

	public static function decrypt(string $string): string
	{
		return Cipher::decrypt($string);
	}

	public static function svgToSymbol(string $svg, string $symbolId): string
	{
		// 1. Pull out the opening <svg> tag (including viewBox)...
		if (!preg_match('#<svg\b([^>]*)viewBox="([^"]+)"([^>]*)>#i', $svg, $m)) {
			// throw new \InvalidArgumentException("SVG must include a viewBox");
			return $svg; // Return the original SVG if no viewBox is found
		}
		$viewBox = $m[2];

		// 2. Grab everything between <svg…> and </svg>
		$inner = (string)preg_replace('#^.*<svg\b[^>]*>\\s*#si', '', (string)preg_replace('#</svg>.*$#si', '', $svg));

		// 3. Build the symbol + wrapper
		return <<<SVG
		<svg xmlns="http://www.w3.org/2000/svg" style="display:none">
			<symbol id="{$symbolId}" viewBox="{$viewBox}">
				{$inner}
			</symbol>
		</svg>
		SVG;
	}

	public static function mailto(string $email, string $subject = '', string $body = '', string $title = ''): string
	{
		return HTMLUtils::mailtoLink(email: $email, subject: $subject, body: $body, title: $title);
	}

	// -------------------------
	// Phone number formatting
	// -------------------------
	public static function formatPhone(string $string, string $countryCode = 'US'): string
	{
		$phone = self::digitsOnly($string);

		if ($countryCode === 'GB' && str_starts_with($phone, '07')) {
			$countryCode = 'GBM'; // Use 'GBM' for mobile numbers in Great Britain
		}

		$formats = [
			'US' => [
				'regex'  => '/(\d{3})(\d{3})(\d{4})/',
				'format' => '($1) $2-$3',
			],
			'CA' => [
				'regex'  => '/(\d{3})(\d{3})(\d{4})/',
				'format' => '($1) $2-$3',
			],
			'GB' => [
				'regex'  => '/(\d{3})(\d{4})(\d{4})/',
				'format' => '($1) $2 $3',
			],
			'GBM' => [
				'regex'  => '/(\d{5})(\d{6})/',
				'format' => '$1 $2',
			],
			'MX' => [
				'regex'  => '/(\d{2,3})(\d{4})(\d{4})/',
				'format' => '$1 $2 $3',
			],
			'AU' => [
				'regex'  => '/(\d{4})(\d{3})(\d{3})/',
				'format' => '$1 $2 $3',
			],
			'FR' => [
				'regex'  => '/(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/',
				'format' => '$1 $2 $3 $4 $5',
			],
			'DE' => [
				'regex'  => '/(\d{3})(\d{3})(\d{4})/',
				'format' => '$1 $2 $3',
			],
			'IT' => [
				'regex'  => '/(\d{3})(\d{3})(\d{4})/',
				'format' => '$1 $2 $3',
			],
			'ES' => [
				'regex'  => '/(\d{3})(\d{3})(\d{3})/',
				'format' => '$1 $2 $3',
			],
		];
		$format = $formats[$countryCode] ?? null;
		if ($format === null) {
			return $phone; // Return the original phone number if no format is found
		}
		$phone = preg_replace($format['regex'], $format['format'], $phone);
		if ($phone === null) {
			return ''; // Return an empty string if the regex replacement fails
		} // Trim any extra spaces

		return trim($phone);
	}

	// -------------------------
	// Total CMS Color Manipulation
	// -------------------------
	/** @return array<string,mixed> */
	public static function hexToColor(string $hex): array
	{
		return [
			'hex'   => $hex,
			'oklch' => ColorData::hexToOklch($hex),
		];
	}

	/** @param ?array<string,mixed> $color */
	public static function hex(?array $color): string
	{
		if ($color === null) {
			return '';
		}

		return $color['hex'] ?? '#000000';
	}

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param ?array<string,mixed> $color
	 */
	public static function rgb(?array $color, int $alpha = 100, bool $wrap = true): string
	{
		if ($color === null) {
			return '';
		}

		$hex = self::hex($color);
		$rgb = ColorData::hexToRgb($hex);

		$color = $alpha === 100 ?
			sprintf('%d %d %d', $rgb['r'], $rgb['g'], $rgb['b']) :
			sprintf('%d %d %d / %.2f', $rgb['r'], $rgb['g'], $rgb['b'], $alpha / 100);

		return $wrap ? sprintf('rgb(%s)', $color) : $color;
	}

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param ?array<string,mixed> $color
	 */
	public static function hsl(?array $color, int $alpha = 100, bool $wrap = true): string
	{
		if ($color === null) {
			return '';
		}

		$hex = self::hex($color);
		$hsl = ColorData::hexToHsl($hex);

		$color = $alpha === 100 ?
			sprintf('%d %d%% %d%%', $hsl['h'], $hsl['s'], $hsl['l']) :
			sprintf('%d %d%% %d%% / %.2f', $hsl['h'], $hsl['s'], $hsl['l'], $alpha / 100);

		return $wrap ? sprintf('hsl(%s)', $color) : $color;
	}

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param ?array<string,mixed> $color
	 */
	public static function color(?array $color, int $alpha = 100, bool $wrap = true): string
	{
		return self::oklch($color, $alpha, $wrap);
	}

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param ?array<string,mixed> $color
	 */
	public static function colour(?array $color, int $alpha = 100, bool $wrap = true): string
	{
		return self::oklch($color, $alpha, $wrap);
	}

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param ?array<string,mixed> $color
	 */
	public static function oklch(?array $color, int $alpha = 100, bool $wrap = true): string
	{
		if ($color === null) {
			return '';
		}

		$oklch = $color['oklch'] ?? ['l' => 0, 'c' => 0, 'h' => 0];

		$color = $alpha === 100 ?
			sprintf('%.3f%% %.3f %.3f', $oklch['l'], $oklch['c'], $oklch['h']) :
			sprintf('%.3f%% %.3f %.3f / %.2f', $oklch['l'], $oklch['c'], $oklch['h'], $alpha / 100);

		return $wrap ? sprintf('oklch(%s)', $color) : $color;
	}

	/**
	 * @param ?array<string,mixed> $color
	 *
	 * @return ?array<string,mixed>
	 */
	public static function lightness(?array $color, string $lightness): ?array
	{
		return self::adjustColor($color, $lightness);
	}

	/**
	 * @param ?array<string,mixed> $color
	 *
	 * @return ?array<string,mixed>
	 */
	public static function chroma(?array $color, string $chroma): ?array
	{
		return self::adjustColor($color, null, $chroma);
	}

	/**
	 * @param ?array<string,mixed> $color
	 *
	 * @return ?array<string,mixed>
	 */
	public static function hue(?array $color, string $hue): ?array
	{
		return self::adjustColor($color, null, null, $hue);
	}

	/**
	 * @param ?array<string,mixed> $color
	 *
	 * @return ?array<string,mixed>
	 */
	public static function adjustColor(?array $color, ?string $lightness = null, ?string $chroma = null, ?string $hue = null): ?array
	{
		if ($color === null) {
			return null;
		}

		$oklch = $color['oklch'] ?? ['l' => 0, 'c' => 0, 'h' => 0];

		$oklch = ColorData::oklchChange($oklch, [
			'l' => $lightness,
			'c' => $chroma,
			'h' => $hue,
		]);

		return [
			'oklch' => $oklch,
			'hex'   => ColorData::oklchToHex($oklch),
		];
	}

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 */
	public static function truncate(?string $string, int $length, bool $keepWords = false): string
	{
		if ($string === null || $string === '') {
			return '';
		}

		$string = strip_tags($string);

		if (strlen($string) > $length) {
			if ($keepWords) {
				$string  = substr($string, 0, $length);
				$space   = strrpos($string, ' ') ?: null;
				$string  = substr($string, 0, $space);
			} else {
				$string = substr($string, 0, $length);
			}
			$string .= '&hellip;';
		}

		return $string;
	}

	public static function truncateWords(string $string, int $length): string
	{
		$string = strip_tags($string);
		$string = preg_replace('/\s+/', ' ', $string) ?? '';
		$words  = explode(' ', $string);

		if (count($words) > $length) {
			$string = implode(' ', array_slice($words, 0, $length));
			$string .= '&hellip;';
		}

		return $string;
	}

	// -------------------------
	// Counters
	// -------------------------
	public static function charcount(string $text): int
	{
		$text = strip_tags($text); // strip HTML
		$text = preg_replace('/\s+/', ' ', $text); // replace multiple spaces with a single space

		return mb_strlen($text ?? '');
	}

	public static function wordcount(string $text): int
	{
		$text  = strip_tags($text); // strip HTML
		$words = preg_split('/[\s,:?!]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

		return is_array($words) ? count($words) : 0;
	}

	public static function readtime(string $text, int $wpm = 180): float
	{
		$wordCount = self::wordcount($text);

		return ceil($wordCount / $wpm);
	}

	// -------------------------
	// Array Manipulation
	// -------------------------
	/**
	 * @param array<mixed> $array
	 *
	 * @return array<mixed>
	 */
	public static function sortBy(array $array, string $key): array
	{
		if ($array === [] || $key === '') {
			return $array;
		}
		usort($array, function (array $a, array $b) use ($key): int {
			if (!isset($a[$key]) || !isset($b[$key])) {
				return 0; // If key doesn't exist, consider them equal
			}

			return $a[$key] <=> $b[$key];
		});

		return $array;
	}

	/**
	 * @param array<mixed> $array
	 *
	 * @return array<mixed>
	 */
	public static function ksort(array $array): array
	{
		ksort($array);

		return $array;
	}

	/**
	 * @param array<mixed> $array
	 *
	 * @return array<mixed>
	 */
	public static function krsort(array $array): array
	{
		krsort($array);

		return $array;
	}

	/**
	 * @param array<mixed> $array
	 *
	 * @return array<mixed>
	 */
	public static function shuffle(array $array): array
	{
		shuffle($array);

		return $array;
	}

	/**
	 * @param array<array<string,mixed>> $collection
	 * @param array<array<string,mixed>> $rules
	 *
	 * @return array<array<string,mixed>>
	 */
	public static function filterCollection(array $collection, array $rules): array
	{
		if ($rules === []) {
			return $collection;
		}

		$refiner = new CollectionRefiner($collection);

		return $refiner->filterUnique($refiner->filter($rules));
	}

	/**
	 * @param array<array<string,mixed>> $collection
	 * @param array<array<string,mixed>> $rules
	 *
	 * @return array<array<string,mixed>>
	 */
	public static function sortCollection(array $collection, array $rules): array
	{
		if ($rules === []) {
			return $collection;
		}

		$sorter = new CollectionSorter($collection);

		return $sorter->sortByRules($rules);
	}

	/**
	 * @param array<int,mixed> $collection
	 *
	 * @return array<int,mixed>
	 */
	public static function paginate(array $collection, int $limit, int $page = 1): array
	{
		$offset = ($page - 1) * $limit;

		return array_slice($collection, $offset, $limit);
	}

	// -------------------------
	// Type Casting
	// -------------------------

	public static function typeof(mixed $variable): string
	{
		return gettype($variable);
	}

	public static function string(mixed $variable): string
	{
		return (string)$variable;
	}

	public static function int(mixed $variable): int
	{
		return (int)$variable;
	}

	public static function float(mixed $variable): float
	{
		return (float)$variable;
	}

	public static function bool(mixed $variable): bool
	{
		return (bool)$variable;
	}

	/**
	 * @return array<mixed>
	 */
	public static function array(mixed $variable): array
	{
		return (array)$variable;
	}

	// -------------------------
	// Utilities
	// -------------------------

	/** @SuppressWarnings("PHPMD.CamelCaseMethodName") */
	public static function var_dump(mixed $variable): string
	{
		return TotalCMSTwigFunctions::var_dump($variable);
	}

	/** @SuppressWarnings("PHPMD.CamelCaseMethodName") */
	public static function print_r(mixed $variable): string
	{
		return TotalCMSTwigFunctions::print_r($variable);
	}

	// -------------------------
	// Markdown Aliases
	// -------------------------

	public static function markdown(mixed $value): string
	{
		// This method serves as an alias for the built-in markdown_to_html filter
		// Uses the same ParsedownMarkdown implementation as the native filter
		if (!is_string($value)) {
			$value = (string)$value;
		}

		// Use the same ParsedownMarkdown class that powers Twig's MarkdownExtension
		static $markdown = null;
		if ($markdown === null) {
			$markdown = new \TotalCMS\Domain\Twig\Markdown\ParsedownMarkdown();
		}

		return $markdown->convert($value);
	}

	// -------------------------
	// Date Manipulation (Chronos)
	// -------------------------

	/**
	 * Parse a date and return a Chronos instance with timezone support.
	 */
	private static function parseDate(mixed $date): Chronos
	{
		$config   = Config::init();
		$timezone = new \DateTimeZone($config->timezone);

		if ($date instanceof Chronos) {
			return $date->setTimezone($timezone);
		}

		return Chronos::parse((string)$date, $timezone);
	}

	/**
	 * Get relative date string (e.g., "2 days ago", "in 3 weeks").
	 */
	public static function dateRelative(mixed $date): string
	{
		try {
			$chronos = self::parseDate($date);

			return $chronos->diffForHumans();
		} catch (\Exception) {
			return (string)$date;
		}
	}

	/**
	 * Format date with custom format string.
	 */
	public static function dateFormat(mixed $date, string $format = 'Y-m-d H:i:s'): string
	{
		try {
			$chronos = self::parseDate($date);

			return $chronos->format($format);
		} catch (\Exception) {
			return (string)$date;
		}
	}

	/**
	 * Add time to date (e.g., "+1 day", "+2 weeks", "+3 months").
	 */
	public static function dateAdd(mixed $date, string $interval): string
	{
		try {
			$chronos = self::parseDate($date);

			return $chronos->modify($interval)->format('c');
		} catch (\Exception) {
			return (string)$date;
		}
	}

	/**
	 * Subtract time from date (e.g., "-1 day", "-2 weeks", "-3 months").
	 */
	public static function dateSubtract(mixed $date, string $interval): string
	{
		try {
			$chronos = self::parseDate($date);
			// If interval doesn't start with -, add it
			if (!str_starts_with($interval, '-')) {
				$interval = '-' . $interval;
			}

			return $chronos->modify($interval)->format('c');
		} catch (\Exception) {
			return (string)$date;
		}
	}

	/**
	 * Get difference between two dates in human readable format.
	 */
	public static function dateDiff(mixed $date1, mixed $date2): string
	{
		try {
			$chronos1 = self::parseDate($date1);
			$chronos2 = self::parseDate($date2);

			return $chronos1->diffForHumans($chronos2);
		} catch (\Exception) {
			return '';
		}
	}

	/**
	 * Get start of period (e.g., "day", "week", "month", "year").
	 */
	public static function dateStartOf(mixed $date, string $unit = 'day'): string
	{
		try {
			$chronos = self::parseDate($date);

			return match ($unit) {
				'day'   => $chronos->startOfDay()->format('c'),
				'week'  => $chronos->startOfWeek()->format('c'),
				'month' => $chronos->startOfMonth()->format('c'),
				'year'  => $chronos->startOfYear()->format('c'),
				default => $chronos->format('c'),
			};
		} catch (\Exception) {
			return (string)$date;
		}
	}

	/**
	 * Get end of period (e.g., "day", "week", "month", "year").
	 */
	public static function dateEndOf(mixed $date, string $unit = 'day'): string
	{
		try {
			$chronos = self::parseDate($date);

			return match ($unit) {
				'day'   => $chronos->endOfDay()->format('c'),
				'week'  => $chronos->endOfWeek()->format('c'),
				'month' => $chronos->endOfMonth()->format('c'),
				'year'  => $chronos->endOfYear()->format('c'),
				default => $chronos->format('c'),
			};
		} catch (\Exception) {
			return (string)$date;
		}
	}

	/**
	 * Check if date is a weekend.
	 */
	public static function dateIsWeekend(mixed $date): bool
	{
		try {
			$chronos = self::parseDate($date);

			return $chronos->isWeekend();
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Check if date is a weekday.
	 */
	public static function dateIsWeekday(mixed $date): bool
	{
		try {
			$chronos = self::parseDate($date);

			return !$chronos->isWeekend();
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Check if date is in the past.
	 */
	public static function dateIsPast(mixed $date): bool
	{
		try {
			$chronos = self::parseDate($date);

			return $chronos->isPast();
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Check if date is in the future.
	 */
	public static function dateIsFuture(mixed $date): bool
	{
		try {
			$chronos = self::parseDate($date);

			return $chronos->isFuture();
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Check if date is today.
	 */
	public static function dateIsToday(mixed $date): bool
	{
		try {
			$chronos = self::parseDate($date);

			return $chronos->isToday();
		} catch (\Exception) {
			return false;
		}
	}

	// -------------------------
	// Price Formatting
	// -------------------------

	/**
	 * Format price value with currency.
	 *
	 * @param mixed $price Price value
	 * @param string $currency Currency symbol or code
	 * @param string $format Format type (prepend, append, none)
	 *
	 * @return string Formatted price string
	 */
	public static function price(mixed $price, string $currency = '$', string $format = 'prepend'): string
	{
		if (empty($price) && $price !== 0 && $price !== '0') {
			return '';
		}

		$numericPrice = is_numeric($price) ? floatval($price) : 0;

		return match ($format) {
			'prepend' => $currency . number_format($numericPrice, 2),
			'append'  => number_format($numericPrice, 2) . $currency,
			'none'    => number_format($numericPrice, 2),
			default   => $currency . number_format($numericPrice, 2),
		};
	}
}
