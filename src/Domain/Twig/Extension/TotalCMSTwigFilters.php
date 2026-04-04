<?php

namespace TotalCMS\Domain\Twig\Extension;

use Cake\Chronos\Chronos;
use PHP_CodeSniffer\Generators\HTML;
use TotalCMS\Domain\Collection\Utilities\CollectionRefiner;
use TotalCMS\Domain\Collection\Utilities\CollectionSorter;
use TotalCMS\Domain\Collection\Utilities\SortRuleParser;
use TotalCMS\Domain\Property\Data\ColorData;
use TotalCMS\Domain\Property\Data\SlugData;
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
		'htmldecode',
		'obfuscate',
		'deobfuscate',
		'encrypt',
		'decrypt',
		'filterCollection',
		'sortCollection',
		'sortCollectionByString',
		'paginate',
		'svgToSymbol',
		'markdown',
		'markdownInline',
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
		'recurringMonthDate',
		'dateIsRecurringDate',
		'price',
		'mailto',
		'prefixSlug',
		'unique',
		'filesize',
		'keyBy',
		'sum',
		'avg',
		'min',
		'max',
		'pluck',
		'groupBy',
		'countBy',
		'toSeconds',
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

	public static function htmldecode(string $string): string
	{
		return html_entity_decode($string);
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

	/**
	 * @param array<mixed> $array
	 *
	 * @return array<mixed>
	 */
	public static function unique(array $array, int $flags = SORT_STRING): array
	{
		return array_unique($array, $flags);
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

	/**
	 * Slugify and prefix a string or array of strings.
	 *
	 * @param string|array<mixed>|null $value String or array of strings to process
	 * @param string $prefix Prefix to prepend to each slugified item (default: '')
	 * @param string $separator Separator to join items (default: ' ')
	 *
	 * @return string Joined string of slugified and prefixed items
	 */
	public static function prefixSlug(string|array|null $value, string $prefix = '', string $separator = ' '): string
	{
		if (in_array($value, [null, '', []], true)) {
			return '';
		}

		// Convert string to single-item array
		$items = is_string($value) ? [$value] : $value;

		$processed = [];
		foreach ($items as $item) {
			if (!is_string($item) || $item === '') {
				continue;
			}

			$slug = SlugData::slugify($prefix . $item);
			if ($slug === '' || $slug === $prefix) {
				continue;
			}

			$processed[] = $slug;
		}

		return implode($separator, $processed);
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

	/**
	 * Convert a time string (H:M:S, M:S, or S) to total seconds.
	 */
	public static function toSeconds(string $time): int
	{
		$parts = array_map(intval(...), explode(':', $time));

		return match (count($parts)) {
			3       => ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2],
			2       => ($parts[0] * 60) + $parts[1],
			default => $parts[0],
		};
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
	 * Convert an array of objects/arrays into an associative array keyed by a specific property.
	 *
	 * This is useful for creating lookup tables from collections, avoiding N+1 query patterns.
	 * Example: `cms.objects("members") | keyBy('id')` creates a lookup table by member ID.
	 *
	 * @param array<mixed>|null $collection Array of objects/arrays
	 * @param string $key The property name to use as the key (defaults to 'id')
	 *
	 * @return array<string,array<string,mixed>> Associative array keyed by the specified property
	 */
	public static function keyBy(?array $collection, string $key = 'id'): array
	{
		if ($collection === null || $collection === []) {
			return [];
		}

		$result = [];
		foreach ($collection as $item) {
			if (!is_array($item)) {
				continue;
			}

			$keyValue = $item[$key] ?? null;
			if ($keyValue === null || $keyValue === '') {
				continue;
			}

			$result[(string)$keyValue] = $item;
		}

		return $result;
	}

	/**
	 * Sum the values of a specific property across all items in a collection.
	 *
	 * This is useful for calculating totals without verbose Twig loops.
	 * Example: `cms.objects("busamount") | sum('amt')` returns the total of all amt values.
	 *
	 * @param array<mixed>|null $collection Array of objects/arrays
	 * @param string $property The property name to sum
	 *
	 * @return float The sum of all values for the specified property
	 */
	public static function sum(?array $collection, string $property): float
	{
		if ($collection === null || $collection === []) {
			return 0.0;
		}

		$total = 0.0;
		foreach ($collection as $item) {
			if (!is_array($item)) {
				continue;
			}

			$value = $item[$property] ?? 0;
			if (is_numeric($value)) {
				$total += (float)$value;
			}
		}

		return $total;
	}

	/**
	 * Calculate the average value of a specific property across all items in a collection.
	 *
	 * Example: `cms.objects("busamount") | avg('amt')` returns the average of all amt values.
	 *
	 * @param array<mixed>|null $collection Array of objects/arrays
	 * @param string $property The property name to average
	 *
	 * @return float The average value (0 if collection is empty)
	 */
	public static function avg(?array $collection, string $property): float
	{
		if ($collection === null || $collection === []) {
			return 0.0;
		}

		$total = 0.0;
		$count = 0;
		foreach ($collection as $item) {
			if (!is_array($item)) {
				continue;
			}

			$value = $item[$property] ?? null;
			if (is_numeric($value)) {
				$total += (float)$value;
				$count++;
			}
		}

		return $count > 0 ? $total / $count : 0.0;
	}

	/**
	 * Get the minimum value of a specific property across all items in a collection.
	 *
	 * Example: `cms.objects("products") | min('price')` returns the lowest price.
	 *
	 * @param array<mixed>|null $collection Array of objects/arrays
	 * @param string $property The property name to find minimum of
	 *
	 * @return float|null The minimum value (null if no valid values found)
	 */
	public static function min(?array $collection, string $property): ?float
	{
		if ($collection === null || $collection === []) {
			return null;
		}

		$min = null;
		foreach ($collection as $item) {
			if (!is_array($item)) {
				continue;
			}

			$value = $item[$property] ?? null;
			if (is_numeric($value)) {
				$floatValue = (float)$value;
				if ($min === null || $floatValue < $min) {
					$min = $floatValue;
				}
			}
		}

		return $min;
	}

	/**
	 * Get the maximum value of a specific property across all items in a collection.
	 *
	 * Example: `cms.objects("products") | max('price')` returns the highest price.
	 *
	 * @param array<mixed>|null $collection Array of objects/arrays
	 * @param string $property The property name to find maximum of
	 *
	 * @return float|null The maximum value (null if no valid values found)
	 */
	public static function max(?array $collection, string $property): ?float
	{
		if ($collection === null || $collection === []) {
			return null;
		}

		$max = null;
		foreach ($collection as $item) {
			if (!is_array($item)) {
				continue;
			}

			$value = $item[$property] ?? null;
			if (is_numeric($value)) {
				$floatValue = (float)$value;
				if ($max === null || $floatValue > $max) {
					$max = $floatValue;
				}
			}
		}

		return $max;
	}

	/**
	 * Extract a single property from all items in a collection.
	 *
	 * Example: `cms.objects("members") | pluck('email')` returns ['a@b.com', 'c@d.com', ...]
	 *
	 * @param array<mixed>|null $collection Array of objects/arrays
	 * @param string $property The property name to extract
	 *
	 * @return array<mixed> Array of extracted values
	 */
	public static function pluck(?array $collection, string $property): array
	{
		if ($collection === null || $collection === []) {
			return [];
		}

		$result = [];
		foreach ($collection as $item) {
			if (!is_array($item)) {
				continue;
			}

			if (array_key_exists($property, $item)) {
				$result[] = $item[$property];
			}
		}

		return $result;
	}

	/**
	 * Group items by a specific property value.
	 *
	 * Example: `cms.objects("posts") | groupBy('category')` returns {news: [...], blog: [...]}
	 *
	 * @param array<mixed>|null $collection Array of objects/arrays
	 * @param string $property The property name to group by
	 *
	 * @return array<string,array<array<string,mixed>>> Grouped items keyed by property value
	 */
	public static function groupBy(?array $collection, string $property): array
	{
		if ($collection === null || $collection === []) {
			return [];
		}

		$result = [];
		foreach ($collection as $item) {
			if (!is_array($item)) {
				continue;
			}

			$key       = $item[$property] ?? '';
			$keyString = is_scalar($key) ? (string)$key : '';

			if ($keyString === '') {
				$keyString = '_ungrouped';
			}

			if (!isset($result[$keyString])) {
				$result[$keyString] = [];
			}
			$result[$keyString][] = $item;
		}

		return $result;
	}

	/**
	 * Count items grouped by a specific property value.
	 *
	 * Example: `cms.objects("posts") | countBy('category')` returns {news: 5, blog: 12}
	 *
	 * @param array<mixed>|null $collection Array of objects/arrays
	 * @param string $property The property name to count by
	 *
	 * @return array<string,int> Counts keyed by property value
	 */
	public static function countBy(?array $collection, string $property): array
	{
		if ($collection === null || $collection === []) {
			return [];
		}

		$result = [];
		foreach ($collection as $item) {
			if (!is_array($item)) {
				continue;
			}

			$key       = $item[$property] ?? '';
			$keyString = is_scalar($key) ? (string)$key : '';

			if ($keyString === '') {
				$keyString = '_ungrouped';
			}

			if (!isset($result[$keyString])) {
				$result[$keyString] = 0;
			}
			$result[$keyString]++;
		}

		return $result;
	}

	/**
	 * @param array<array<string,mixed>>|null $collection
	 * @param array<array<string,mixed>> $rules
	 *
	 * @return array<array<string,mixed>>
	 */
	public static function filterCollection(?array $collection, array $rules): array
	{
		if ($collection === null || $collection === [] || $rules === []) {
			return $collection ?? [];
		}

		$refiner = new CollectionRefiner($collection);

		return $refiner->filterUnique($refiner->filter($rules));
	}

	/**
	 * @param array<array<string,mixed>>|null $collection
	 * @param array<array<string,mixed>> $rules
	 *
	 * @return array<array<string,mixed>>
	 */
	public static function sortCollection(?array $collection, array $rules): array
	{
		if ($collection === null || $collection === [] || $rules === []) {
			return $collection ?? [];
		}

		$sorter = new CollectionSorter($collection);

		return $sorter->sortByRules($rules);
	}

	/**
	 * Sort a collection using string format: "date:desc,title:asc:natural".
	 *
	 * @param array<array<string,mixed>>|null $collection
	 *
	 * @return array<array<string,mixed>>
	 */
	public static function sortCollectionByString(?array $collection, string $sortString): array
	{
		if ($collection === null || $collection === [] || $sortString === '') {
			return $collection ?? [];
		}

		$rules = SortRuleParser::parse($sortString);
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

	public static function markdownInline(mixed $value): string
	{
		// Inline-only markdown processing (no <p> tags or block elements)
		// Uses Parsedown's line() method for inline elements only
		if (!is_string($value)) {
			$value = (string)$value;
		}

		// Use ParsedownExtra with safe mode for inline processing
		static $parsedown = null;
		if ($parsedown === null) {
			$parsedown = new \ParsedownExtra();
			$parsedown->setSafeMode(true);
			$parsedown->setBreaksEnabled(false); // No line breaks in inline mode
		}

		return $parsedown->line($value);
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
	 * Uses Chronos with RelativeTimeFormatter for locale-aware output.
	 */
	public static function dateRelative(mixed $date): string
	{
		try {
			$chronos = self::parseDate($date);

			return $chronos->diffForHumans();
		} catch (\Throwable) {
			// Fallback when intl extension is missing or date parsing fails
			try {
				$chronos = self::parseDate($date);

				return $chronos->format('M j, Y');
			} catch (\Throwable) {
				return (string)$date;
			}
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

	/**
	 * Get the recurring date for a target month.
	 *
	 * Useful for subscription billing dates - if someone signed up on Jan 31st,
	 * their February billing date would be Feb 28th (clamped to end of month).
	 *
	 * @param mixed $date The original date (e.g., subscription start date)
	 * @param mixed $targetDate Optional target date/month (defaults to current month)
	 *
	 * @return string ISO 8601 date string
	 */
	public static function recurringMonthDate(mixed $date, mixed $targetDate = null): string
	{
		try {
			$chronos = self::parseDate($date);
			$target  = $targetDate !== null ? self::parseDate($targetDate) : Chronos::now();

			$originalDay       = $chronos->day;
			$daysInTargetMonth = $target->daysInMonth;

			$clampedDay = min($originalDay, $daysInTargetMonth);

			return $target->day($clampedDay)->format('c');
		} catch (\Exception) {
			return (string)$date;
		}
	}

	/**
	 * Check if a comparison date falls on the recurring day of the original date.
	 *
	 * Useful for checking if today is a billing day for a subscription.
	 *
	 * @param mixed $date The original date (e.g., subscription start date)
	 * @param mixed $compareDate Optional date to compare against (defaults to today)
	 */
	public static function dateIsRecurringDate(mixed $date, mixed $compareDate = null): bool
	{
		try {
			$chronos = self::parseDate($date);
			$compare = $compareDate !== null ? self::parseDate($compareDate) : Chronos::now();

			$originalDay        = $chronos->day;
			$daysInCompareMonth = $compare->daysInMonth;

			// Clamp the original day to the compare month's max days
			$expectedDay = min($originalDay, $daysInCompareMonth);

			return $compare->day === $expectedDay;
		} catch (\Exception) {
			return false;
		}
	}

	// -------------------------
	// Price Formatting
	// -------------------------

	/**
	 * Format bytes to human-readable file size using decimal units (1000).
	 * This matches Mac Finder and browser display conventions.
	 *
	 * @param mixed $bytes Number of bytes
	 * @param int $decimals Number of decimal places (default: 1)
	 *
	 * @return string Formatted file size (e.g., "1.5 MB")
	 */
	public static function filesize(mixed $bytes, int $decimals = 1): string
	{
		if (!is_numeric($bytes) || $bytes < 0) {
			return '0 B';
		}

		$bytes = (float)$bytes;

		if ($bytes === 0.0) {
			return '0 B';
		}

		// Use 1000 (decimal) to match Mac/browser display, not 1024 (binary)
		$units  = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
		$factor = (int)floor(log($bytes, 1000));
		$factor = max(0, min($factor, count($units) - 1)); // Clamp to valid range

		$value = $bytes / 1000 ** $factor;

		// Don't show decimals for B or KB
		if ($factor <= 1) {
			return sprintf('%d %s', (int)round($value), $units[$factor]);
		}

		return sprintf('%.' . $decimals . 'f %s', $value, $units[$factor]);
	}

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
