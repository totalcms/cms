<?php

namespace TotalCMS\Domain\Twig;

use Twig\TwigFunction;

/**
 * Twig Functions for Total CMS.
 */
final class TotalCMSTwigFunctions
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
		'istype',
		'var_dump',
		'print_r',
		'json_pretty',
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
	// Custom Functions
	// -------------------------
	/**
	 * @param array<mixed> $data
	 *
	 * @return array<array<string,string>>
	 */
	public static function selectOptions(array $data, string $label = '', string $value = ''): array
	{
		// this takes a normal array and converts it to an array of arrays with label and value keys
		// the resulting array can be used for select options in a form
		if (empty($value) || empty($label)) {
			return array_map(fn ($value): array => ['label' => $value, 'value' => $value], $data);
		}

		return array_map(fn ($item): array => ['label' => $item[$label], 'value' => $item[$value]], $data);
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
		usort($array, function ($a, $b) use ($key) {
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

	// -------------------------
	// Utilities
	// -------------------------

	/** @SuppressWarnings(PHPMD.CamelCaseMethodName) */
	public static function var_dump(mixed $variable): string
	{
		ob_start();
		var_dump($variable);
		$content = ob_get_contents();
		ob_end_clean();

		return "<pre>$content</pre>";
	}

	/** @SuppressWarnings(PHPMD.CamelCaseMethodName) */
	public static function print_r(mixed $variable): string
	{
		return '<pre>' . (string)print_r($variable, true) . '</pre>';
	}

	/** @SuppressWarnings(PHPMD.CamelCaseMethodName) */
	public static function json_pretty(mixed $variable): string
	{
		return json_encode($variable, JSON_PRETTY_PRINT) ?: '';
	}
}
