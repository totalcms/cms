<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Domain\ImageWorks\Exception\InvalidImageParamException;

/**
 * The gate every public ImageWorks route puts in front of Glide. Glide hashes
 * the query string into the cache key and quietly ignores a value it cannot
 * parse, so `w=-5905 OR 7283=CAST(...)` used to be a fresh full-size render
 * per request: a SQL-injection scanner against seven image URLs held a
 * 2-core server at load 8 for a day (Nov 2026).
 *
 * Every key ImageWorks understands has a rule for its value; a value that
 * breaks its rule throws, and the route answers 400 before any image work.
 * Keys ImageWorks does not understand are dropped (Glide would ignore them
 * anyway, and tracking params such as `fbclid` must keep working). Numbers
 * come back as ints, so `w=0300` and `w=300` share one cache file.
 *
 * Only query input goes through here. Params Total CMS builds itself (Twig,
 * the favicon, the warmer) are trusted and reach ImageGenerator directly.
 */
final class ImageParamValidator
{
	/** Whole numbers: [min, max]. A decimal is accepted and truncated, as Glide would. */
	private const INTEGERS = [
		'w'             => [0, 10000],
		'h'             => [0, 10000],
		'q'             => [0, 100],
		'blur'          => [0, 100],
		'pixel'         => [0, 1000],
		'sharp'         => [0, 100],
		'bri'           => [-100, 100],
		'con'           => [-100, 100],
		'markalpha'     => [0, 100],
		'marktextalpha' => [0, 100],
		'marktextsize'  => [1, 1000],
		// Wrapped text: the most lines before the font size steps down
		'marktextlines' => [1, 6],
		'marktextangle' => [-360, 360],
		// Tiled mode: marks across the longer side
		'markrepeat'     => [1, 12],
		'marktextrepeat' => [1, 12],
		// Outline width and shadow offset, as a percentage of the font size
		'marktextstroke' => [0, 25],
		'marktextshadow' => [0, 25],
	];

	/** Fractional numbers: [min, max]. */
	private const DECIMALS = [
		'gam' => [0.1, 9.99],
		'dpr' => [0, 8],
	];

	private const ENUMS = [
		'fm'   => ['avif', 'gif', 'jpg', 'jpeg', 'pjpg', 'png', 'webp', 'tiff', 'heic'],
		'flip' => ['v', 'h', 'both'],
		'or'   => ['auto', '0', '90', '180', '270'],
		'filt' => ['greyscale', 'sepia'],
		// A markimage layer cut to a circle (a profile photo)
		'markshape' => ['circle'],
		// A fill that fades out: solid at this edge, clear at the other
		'markgradient' => ['top', 'bottom', 'left', 'right'],
		'marktextalign' => ['left', 'center', 'right'],
	];

	/**
	 * Patterns for the string-valued keys. Each is as loose as the formats
	 * Total CMS documents allow, and none admits a space, quote, `=` or
	 * parenthesis — the characters an injection payload cannot do without.
	 */
	private const PATTERNS = [
		// contain, max, fill-max, crop-top-left, crop-50-50-1.5, crop-focalpoint
		'fit'          => '/^(contain|fill-max|fill|max|stretch|cover|crop)(-[a-z0-9.]+){0,4}$/',
		'markfit'      => '/^(contain|fill-max|fill|max|stretch|cover|crop)(-[a-z0-9.]+){0,4}$/',
		'marktextfit'  => '/^(contain|fill-max|fill|max|stretch|cover|crop)(-[a-z0-9.]+){0,4}$/',
		'crop'         => '/^\d{1,5},\d{1,5},\d{1,5},\d{1,5}$/',
		// Glide relative dimensions: 20, 12.5, 50w, 10h
		'markw'        => '/^\d{1,5}(\.\d+)?[wh]?$/',
		'markh'        => '/^\d{1,5}(\.\d+)?[wh]?$/',
		'markx'        => '/^\d{1,5}(\.\d+)?[wh]?$/',
		'marky'        => '/^\d{1,5}(\.\d+)?[wh]?$/',
		'markpad'      => '/^\d{1,5}(\.\d+)?[wh]?$/',
		'marktextw'    => '/^\d{1,5}(\.\d+)?[wh]?$/',
		'marktexth'    => '/^\d{1,5}(\.\d+)?[wh]?$/',
		'marktextx'    => '/^\d{1,5}(\.\d+)?[wh]?$/',
		'marktexty'    => '/^\d{1,5}(\.\d+)?[wh]?$/',
		'marktextpad'  => '/^\d{1,5}(\.\d+)?[wh]?$/',
		// Colors (hex, names, palette-N) and positions; border is "width,color,method"
		'bg'                  => '/^[A-Za-z0-9#,._-]{1,64}$/',
		'border'              => '/^[A-Za-z0-9#,._-]{1,64}$/',
		'markpos'             => '/^[A-Za-z0-9#,._-]{1,64}$/',
		'marktextpos'         => '/^[A-Za-z0-9#,._-]{1,64}$/',
		'marktextcolor'       => '/^[A-Za-z0-9#,._-]{1,64}$/',
		'marktextbg'          => '/^[A-Za-z0-9#,._-]{1,64}$/',
		'marktextstrokecolor' => '/^[A-Za-z0-9#,._-]{1,64}$/',
		'marktextshadowcolor' => '/^[A-Za-z0-9#,._-]{1,64}$/',
		'markfill'            => '/^[A-Za-z0-9#,._-]{1,64}$/',
		// File names: a watermark image, a font
		'mark'          => '/^[A-Za-z0-9 ._\/-]{1,255}$/',
		'marktextfont'  => '/^[A-Za-z0-9 ._\/-]{1,255}$/',
		// An image on a record: collection/id/property, plus a gallery image
		// name or first/last. Ids can be emails.
		'markimage'     => '/^[A-Za-z0-9_-]{1,64}\/[A-Za-z0-9_.@+-]{1,128}\/[A-Za-z0-9_-]{1,64}(\/[A-Za-z0-9 ._-]{1,128})?$/',
		// One preset or a comma list. An unknown name is harmless: Glide
		// leaves `p` out of the cache key and skips presets it does not know.
		'p'             => '/^[A-Za-z0-9_-]{1,64}(,[A-Za-z0-9_-]{1,64}){0,7}$/',
	];

	/** Watermark text is free text; it only has to be printable and short. */
	private const TEXT_MAX = 255;

	/**
	 * @param array<array-key,mixed> $query the request's query params
	 *
	 * @throws InvalidImageParamException
	 *
	 * @return array<string,int|float|string|list<array<string,int|float|string>>>
	 */
	public static function validate(array $query): array
	{
		$params = [];

		foreach ($query as $key => $value) {
			$key = (string)$key;
			if ($key === 'marks') {
				$params['marks'] = self::marks($value);
				continue;
			}

			if (!self::isKnown($key)) {
				continue;
			}

			$checked = self::scalar($key, $value);
			if ($checked !== null) {
				$params[$key] = $checked;
			}
		}

		if (($params['marks'] ?? null) === []) {
			unset($params['marks']);
		}

		// Each text layer is a font render, and anyone can write a URL.
		if (self::layerCount($params) > MarkLayers::MAX) {
			throw InvalidImageParamException::forKey('marks');
		}

		return $params;
	}

	/**
	 * The `marks` list: up to MarkLayers::MAX entries, each a set of the
	 * `mark*` keys a single mark takes. An entry that draws nothing, or
	 * nests anything deeper, is a 400 like any other bad value.
	 *
	 * @return list<array<string,int|float|string>>
	 */
	private static function marks(mixed $value): array
	{
		if (!is_array($value) || count($value) > MarkLayers::MAX) {
			throw InvalidImageParamException::forKey('marks');
		}

		$marks = [];
		foreach ($value as $entry) {
			if (!is_array($entry)) {
				throw InvalidImageParamException::forKey('marks');
			}

			$layer = [];
			foreach ($entry as $key => $item) {
				$key = (string)$key;
				if (!str_starts_with($key, 'mark') || !self::isKnown($key)) {
					continue;
				}

				$checked = self::scalar($key, $item);
				if ($checked !== null) {
					$layer[$key] = $checked;
				}
			}

			if (MarkLayers::fromFlat($layer) === []) {
				throw InvalidImageParamException::forKey('marks');
			}

			$marks[] = $layer;
		}

		return $marks;
	}

	/** @param array<string,int|float|string|list<array<string,int|float|string>>> $params */
	private static function layerCount(array $params): int
	{
		$marks = $params['marks'] ?? [];
		$count = count(MarkLayers::fromFlat($params));
		foreach (is_array($marks) ? $marks : [] as $entry) {
			$count += count(MarkLayers::fromFlat($entry));
		}

		return $count;
	}

	/** The checked value, or null for an empty one ("not set", as it always has meant). */
	private static function scalar(string $key, mixed $value): int|float|string|null
	{
		if (!is_scalar($value) || is_bool($value)) {
			throw InvalidImageParamException::forKey($key);
		}

		$value = trim((string)$value);

		return $value === '' ? null : self::checked($key, $value);
	}

	private static function isKnown(string $key): bool
	{
		return isset(self::INTEGERS[$key])
			|| isset(self::DECIMALS[$key])
			|| isset(self::ENUMS[$key])
			|| isset(self::PATTERNS[$key])
			|| $key === 'marktext';
	}

	private static function checked(string $key, string $value): int|float|string
	{
		// A font size can be a share of the image, as `6w` or `4.5h`
		if ($key === 'marktextsize' && preg_match('/^(\d{1,3}(\.\d{1,2})?)[wh]$/i', $value, $match) === 1) {
			if ((float)$match[1] <= 0 || (float)$match[1] > 100) {
				throw InvalidImageParamException::forKey($key);
			}

			return strtolower($value);
		}

		if (isset(self::INTEGERS[$key])) {
			[$min, $max] = self::INTEGERS[$key];

			return (int)self::number($key, $value, $min, $max);
		}

		if (isset(self::DECIMALS[$key])) {
			[$min, $max] = self::DECIMALS[$key];
			$number      = round(self::number($key, $value, $min, $max), 2);

			return floor($number) === $number ? (int)$number : $number;
		}

		if (isset(self::ENUMS[$key])) {
			$value = strtolower($value);
			if (!in_array($value, self::ENUMS[$key], true)) {
				throw InvalidImageParamException::forKey($key);
			}

			return $value;
		}

		if ($key === 'marktext') {
			if (mb_strlen($value) > self::TEXT_MAX || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
				throw InvalidImageParamException::forKey($key);
			}

			return $value;
		}

		$isFile = in_array($key, ['mark', 'marktextfont', 'markimage'], true);
		if (preg_match(self::PATTERNS[$key], $value) !== 1 || ($isFile && str_contains($value, '..'))) {
			throw InvalidImageParamException::forKey($key);
		}

		return $value;
	}

	private static function number(string $key, string $value, int|float $min, int|float $max): float
	{
		if (preg_match('/^-?\d{1,6}(\.\d{1,6})?$/', $value) !== 1) {
			throw InvalidImageParamException::forKey($key);
		}

		$number = (float)$value;
		if ($number < $min || $number > $max) {
			throw InvalidImageParamException::forKey($key);
		}

		return $number;
	}
}
