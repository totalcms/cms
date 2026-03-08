<?php

namespace TotalCMS\Domain\Twig\Extension;

use TotalCMS\Domain\Rendering\Utilities\EmbedBuilder;
use Twig\TwigFunction;

/** @SuppressWarnings("PHPMD.TooManyPublicMethods") */
class TotalCMSTwigFunctions
{
	/** @var array<string> */
	public static array $phpFunctions = [
		'uniqid',
		'floor',
		'ceil',
		'addslashes',
		'chunk_split',
		'md5',
		'sha1',
		'explode',
		'strpos',
		'similar_text',
		'str_pad',
		'strlen',
		'strrpos',
		'wordwrap',
		'gettype',
		'str_contains',
		'str_starts_with',
		'str_ends_with',
		'json_decode',
		'http_build_query',
	];

	/** @var array<string> */
	public static array $customFunctions = [
		'selectOptions',
		'ksort',
		'krsort',
		'sortByKey',
		'next',
		'prev',
		'setSessionData',
		'istype',
		'var_dump',
		'print_r',
		'json_pretty',
		'embed',
		'youtube',
		'vimeo',
		'video',
		'audio',
		'iframe',
		'imageExists',
		'fileExists',
		'svgSymbol',
		// Friendly aliases for PHP functions
		'contains',
		'startsWith',
		'endsWith',
		'indexOf',
		'lastIndexOf',
		'buildQuery',
		'parseJson',
		'typeof',
	];

	/** @return array<TwigFunction> */
	public static function getFunctions(): array
	{
		$twigFunctions = [];

		foreach (self::$customFunctions as $function) {
			$twigFunctions[] = new TwigFunction($function, [self::class, $function], ['is_safe' => ['html']]);
		}

		foreach (self::$phpFunctions as $function) {
			// @phpstan-ignore-next-line
			$twigFunctions[] = new TwigFunction($function, $function, ['is_safe' => ['html']]);
		}

		return $twigFunctions;
	}

	// -------------------------
	// Navigation Functions
	// -------------------------

	/**
	 * Find the next item in a list relative to the current item ID.
	 * Accepts an array of objects (with 'id' field) or a flat array of IDs.
	 *
	 * @param array<mixed> $items
	 */
	public static function next(array $items, string $currentId, bool $wrap = false): mixed
	{
		return self::findAdjacentItem($items, $currentId, 1, $wrap);
	}

	/**
	 * Find the previous item in a list relative to the current item ID.
	 * Accepts an array of objects (with 'id' field) or a flat array of IDs.
	 *
	 * @param array<mixed> $items
	 */
	public static function prev(array $items, string $currentId, bool $wrap = false): mixed
	{
		return self::findAdjacentItem($items, $currentId, -1, $wrap);
	}

	/** @param array<mixed> $items */
	private static function findAdjacentItem(array $items, string $currentId, int $direction, bool $wrap): mixed
	{
		$items = array_values($items);
		$count = count($items);

		foreach ($items as $index => $item) {
			$id = is_array($item) ? (string)($item['id'] ?? '') : (string)$item;
			if ($id !== $currentId) {
				continue;
			}

			$targetIndex = $index + $direction;
			if ($targetIndex >= 0 && $targetIndex < $count) {
				return $items[$targetIndex];
			}
			if ($wrap && $count > 0) {
				return $items[($targetIndex + $count) % $count];
			}

			return null;
		}

		return null;
	}

	// -------------------------
	// Session Functions
	// -------------------------

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public static function setSessionData(string $key, mixed $value): string
	{
		$_SESSION[$key] = $value;

		return '';
	}

	// -------------------------
	// Custom Functions
	// -------------------------
	/**
	 * @param ?array<mixed> $data
	 *
	 * @return array<array<string,string>>
	 */
	public static function selectOptions(?array $data, string $label = '', string $value = ''): array
	{
		if ($data === null || $data === []) {
			return [];
		}
		// this takes a normal array and converts it to an array of arrays with label and value keys
		// the resulting array can be used for select options in a form
		if ($value === '' || $label === '') {
			return array_map(fn ($value): array => ['label' => $value, 'value' => $value], $data);
		}

		return array_map(fn (array $item): array => ['label' => $item[$label], 'value' => $item[$value]], $data);
	}

	public static function istype(mixed $variable, string $type): bool
	{
		return gettype($variable) === $type;
	}

	/**
	 * @param array<mixed> $array
	 *
	 * @return array<mixed>
	 */
	public static function sortByKey(array $array, string $key = 'id'): array
	{
		usort($array, function ($a, $b) use ($key): int {
			if ((!is_array($a) && !is_object($a)) || (!is_array($b) && !is_object($b))) {
				return 0;
			}
			if (!is_array($a)) {
				$a = (array)$a;
			}
			if (!is_array($b)) {
				$b = (array)$b;
			}
			if (!array_key_exists($key, $a) || !array_key_exists($key, $b)) {
				return 0;
			}

			// Use case-insensitive comparison for strings
			if (is_string($a[$key]) && is_string($b[$key])) {
				return strcasecmp($a[$key], $b[$key]);
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

	/** @param array<string,mixed> $options */
	public static function embed(string $url, array $options = []): string
	{
		return EmbedBuilder::embed($url, $options);
	}

	/** @param array<string,mixed> $options */
	public static function youtube(string $url, array $options = []): string
	{
		return EmbedBuilder::youtube($url, $options);
	}

	/** @param array<string,mixed> $options */
	public static function vimeo(string $url, array $options = []): string
	{
		return EmbedBuilder::vimeo($url, $options);
	}

	/** @param array<string,mixed> $options */
	public static function video(string $url, array $options = []): string
	{
		return EmbedBuilder::video($url, $options);
	}

	/** @param array<string,mixed> $options */
	public static function audio(string $url, array $options = []): string
	{
		return EmbedBuilder::audio($url, $options);
	}

	public static function iframe(string $url): string
	{
		return EmbedBuilder::iframe($url);
	}

	public static function fileExists(mixed $file): bool
	{
		if (!is_array($file)) {
			return false;
		}
		if (!isset($file['size'])) {
			return false;
		}

		return intval($file['size']) !== 0;
	}

	public static function imageExists(mixed $image): bool
	{
		return self::fileExists($image);
	}

	public static function svgSymbol(string $id): string
	{
		return '<svg><use href="#' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"></use></svg>';
	}

	// -------------------------
	// Friendly PHP Aliases
	// -------------------------

	public static function contains(string $haystack, string $needle): bool
	{
		return str_contains($haystack, $needle);
	}

	public static function startsWith(string $haystack, string $needle): bool
	{
		return str_starts_with($haystack, $needle);
	}

	public static function endsWith(string $haystack, string $needle): bool
	{
		return str_ends_with($haystack, $needle);
	}

	public static function indexOf(string $haystack, string $needle, int $offset = 0): int|false
	{
		return strpos($haystack, $needle, $offset);
	}

	public static function lastIndexOf(string $haystack, string $needle, int $offset = 0): int|false
	{
		return strrpos($haystack, $needle, $offset);
	}

	/** @param array<string,mixed> $data */
	public static function buildQuery(array $data): string
	{
		return http_build_query($data);
	}

	/** @return array<mixed>|null */
	public static function parseJson(string $json): ?array
	{
		$result = json_decode($json, true);

		return is_array($result) ? $result : null;
	}

	public static function typeof(mixed $variable): string
	{
		return gettype($variable);
	}

	// -------------------------
	// Utilities
	// -------------------------

	/** @SuppressWarnings("PHPMD.CamelCaseMethodName") */
	public static function var_dump(mixed $variable): string
	{
		ob_start();
		var_dump($variable);
		$content = ob_get_contents();
		ob_end_clean();

		return "<pre>$content</pre>";
	}

	/** @SuppressWarnings("PHPMD.CamelCaseMethodName") */
	public static function print_r(mixed $variable): string
	{
		return '<pre>' . print_r($variable, true) . '</pre>';
	}

	/** @SuppressWarnings("PHPMD.CamelCaseMethodName") */
	public static function json_pretty(mixed $variable): string
	{
		return json_encode($variable, JSON_PRETTY_PRINT) ?: '';
	}
}
