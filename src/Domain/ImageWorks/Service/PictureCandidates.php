<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Support\Config;

/**
 * The width and format rules of a responsive `<picture>`, in one place so
 * `cms.render.picture()` and the cache warmer ask for exactly the same files.
 */
final readonly class PictureCandidates
{
	/**
	 * What `picture()` does with no `widths` / `formats` / `sizes` given and
	 * nothing under `imageworks.picture` in settings. The widths span phone
	 * to a 2x desktop hero; the formats are the two every current browser
	 * negotiates, best first; `100vw` is the honest default for `sizes` —
	 * it is what a browser assumes anyway when the attribute is missing,
	 * so stating it changes nothing until a template narrows it.
	 *
	 * @var array{widths: list<int>, formats: list<string>, sizes: string}
	 */
	public const DEFAULTS = [
		'widths'  => [480, 768, 1024, 1440, 1920],
		'formats' => ['avif', 'webp'],
		'sizes'   => '100vw',
	];

	/** ImageWorks output extension → the MIME type a `<source type>` must carry. */
	public const MIME = [
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'pjpg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
	];

	/**
	 * `$options` over `imageworks.picture` in settings over {@see DEFAULTS}.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return array{widths: list<int>, formats: list<string>, sizes: string}
	 */
	public static function settings(?Config $config, array $options = []): array
	{
		$settings = $config->imageworks['picture'] ?? [];

		return [
			'widths'  => self::intList($options['widths'] ?? $settings['widths'] ?? self::DEFAULTS['widths']),
			'formats' => self::formatList($options['formats'] ?? $settings['formats'] ?? self::DEFAULTS['formats']),
			'sizes'   => (string)($options['sizes'] ?? $settings['sizes'] ?? self::DEFAULTS['sizes']),
		];
	}

	/**
	 * Ascending, unique, positive widths no wider than the source or the
	 * `w` ceiling in `$base`, with the source width itself appended as the
	 * top candidate when it is known, caps the list and is not already
	 * listed — the largest candidate is then the true full-resolution file.
	 * With an unknown source width (a record that predates dimension
	 * capture) the list is used as given; there is nothing to guard against.
	 *
	 * @param list<int> $widths
	 * @param array<string,mixed> $image
	 * @param array<string,string|int> $base
	 *
	 * @return list<int>
	 */
	public static function widths(array $widths, array $image, array $base): array
	{
		$ceiling = isset($base['w']) ? (int)$base['w'] : null;
		unset($base['w']);

		$sourceWidth = self::sourceWidth($image, $base);
		$max         = $sourceWidth > 0 ? $sourceWidth : PHP_INT_MAX;
		if ($ceiling !== null && $ceiling > 0) {
			$max = min($max, $ceiling);
		}

		$out = array_values(array_unique(array_filter($widths, static fn (int $w): bool => $w > 0 && $w <= $max)));
		sort($out);

		if ($sourceWidth > 0 && $max === $sourceWidth && !in_array($sourceWidth, $out, true)) {
			$out[] = $sourceWidth;
		}

		return $out;
	}

	/**
	 * The format the image is stored in, which is what its `<img>` fallback
	 * (or, for a dark variant, its last-resort `<source>`) serves.
	 *
	 * @param array<string,mixed> $image
	 */
	public static function nativeFormat(array $image): string
	{
		$native = strtolower(pathinfo((string)($image['name'] ?? ''), PATHINFO_EXTENSION));

		return isset(self::MIME[$native]) ? $native : 'jpg';
	}

	/**
	 * Every format `picture()` serves for one image: the given formats, then
	 * the image's own, once each. A GIF gets only itself — re-encoding to a
	 * still format would drop the animation.
	 *
	 * @param list<string> $formats
	 * @param array<string,mixed> $image
	 *
	 * @return list<string>
	 */
	public static function formats(array $formats, array $image): array
	{
		$native = self::nativeFormat($image);
		if ($native === 'gif') {
			return ['gif'];
		}

		return array_values(array_unique([...self::formatList($formats), $native]));
	}

	/**
	 * The width candidates are cut from: the stored width, or the `crop`
	 * rect's when the transforms carry one.
	 *
	 * @param array<string,mixed> $image
	 * @param array<string,string|int> $base
	 */
	private static function sourceWidth(array $image, array $base): int
	{
		return ImageDimensionCalculator::sourceDimensions((int)($image['width'] ?? 0), (int)($image['height'] ?? 0), $base)['width'];
	}

	/** @return list<int> */
	private static function intList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		return array_values(array_map(static fn (mixed $v): int => (int)$v, array_filter($value, is_numeric(...))));
	}

	/**
	 * Only formats ImageWorks can write and a `<source type>` can name.
	 *
	 * @return list<string>
	 */
	private static function formatList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		$out = [];
		foreach ($value as $format) {
			$format = strtolower((string)$format);
			if (isset(self::MIME[$format]) && !in_array($format, $out, true)) {
				$out[] = $format;
			}
		}

		return $out;
	}
}
