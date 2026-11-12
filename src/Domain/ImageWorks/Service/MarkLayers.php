<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

/**
 * Turns watermark params into the list of layers drawn over an image.
 *
 * A layer is one of four kinds, each taking the keys a single mark takes:
 *   - logo:  `mark`, a file in the watermarks gallery, with the `mark*` keys;
 *   - image: `markimage`, an image on a record (`collection/id/property`,
 *            plus a gallery image or nested path), with the `mark*` keys;
 *   - fill:  `markfill`, a color laid over the image or a band of it,
 *            flat or fading out (`markgradient`), with the `mark*` keys;
 *   - text:  `marktext`, with the `marktext*` keys.
 *
 * The flat params of the original API read as up to two layers, logo then
 * text, and each entry of `marks` reads the same way, so `{mark, marktext}`
 * inside the list is two layers just as it is outside it.
 */
final class MarkLayers
{
	public const LOGO  = 'logo';
	public const IMAGE = 'image';
	public const TEXT  = 'text';
	public const FILL  = 'fill';

	/** Layers one request may ask for: each text layer is a font render. */
	public const MAX = 6;

	/** A logo or image with no size set spans the image, as Glide's mark did. */
	private const DEFAULT_WIDTH = '100w';
	/** A fill with no height set covers the image top to bottom. */
	private const DEFAULT_HEIGHT = '100h';

	/** Keys that belong to one image kind only. */
	private const IMAGE_ONLY = ['markimage', 'markshape'];
	private const FILL_ONLY  = ['markfill', 'markgradient'];

	/**
	 * Every layer the params ask for: the flat params first, then `marks` in
	 * order, at most MAX.
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function fromParams(array $params): array
	{
		$layers = self::fromFlat($params);

		$marks = $params['marks'] ?? [];
		if (is_array($marks)) {
			foreach ($marks as $entry) {
				if (is_array($entry)) {
					array_push($layers, ...self::fromFlat($entry));
				}
			}
		}

		return array_slice($layers, 0, self::MAX);
	}

	/**
	 * The layers one flat set of params describes: an image, logo or fill
	 * layer, then a text layer. They share the `mark*` sizing keys, so one
	 * set draws one of them: `markimage`, else `mark`, else `markfill`.
	 *
	 * @param array<array-key,mixed> $params
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function fromFlat(array $params): array
	{
		$layers = [];
		$image  = self::keys($params, false);

		if (self::filled($image['markimage'] ?? null)) {
			$layers[] = ['kind' => self::IMAGE] + self::withDefaultWidth(self::without($image, self::FILL_ONLY));
		} elseif (self::filled($image['mark'] ?? null)) {
			$layers[] = ['kind' => self::LOGO] + self::withDefaultWidth(self::without($image, [...self::IMAGE_ONLY, ...self::FILL_ONLY]));
		} elseif (self::filled($image['markfill'] ?? null)) {
			$fill = self::withDefaultWidth(self::without($image, self::IMAGE_ONLY));
			$fill['markh'] ??= self::DEFAULT_HEIGHT;
			$layers[] = ['kind' => self::FILL] + $fill;
		}

		$text = self::keys($params, true);
		if (self::filled($text['marktext'] ?? null)) {
			$layers[] = ['kind' => self::TEXT] + $text;
		}

		return $layers;
	}

	/**
	 * The layers drawn on top from the property's watermark settings, and the
	 * URL's layers with any flat mark those settings replace taken out.
	 *
	 * A settings logo replaces the URL's flat logo and a settings text mark
	 * its flat text, as the merge they replace did, so a template that sends
	 * the same logo the schema enforces still shows one. The settings are no
	 * longer merged key by key, so `markalpha=0` in a URL cannot fade them.
	 * Layers the URL lists in `marks` are always kept.
	 *
	 * @param array<string,mixed> $params
	 * @param array<array-key,mixed> $settings
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function withSettings(array $params, array $settings): array
	{
		$enforced = self::fromParams($settings);
		if ($enforced === []) {
			return self::fromParams($params);
		}

		$enforcedKinds = array_column(self::fromFlat($settings), 'kind');
		// A URL fill is a backdrop, not a mark the schema replaces.
		$flat          = array_values(array_filter(
			self::fromFlat($params),
			fn (array $layer): bool => !self::replaced($layer['kind'], $enforcedKinds),
		));

		$listed = self::fromParams(['marks' => $params['marks'] ?? []]);

		return [...array_slice([...$flat, ...$listed], 0, self::MAX), ...$enforced];
	}

	/** @param list<string> $enforcedKinds */
	private static function replaced(string $kind, array $enforcedKinds): bool
	{
		return match ($kind) {
			self::TEXT              => in_array(self::TEXT, $enforcedKinds, true),
			self::LOGO, self::IMAGE => in_array(self::LOGO, $enforcedKinds, true) || in_array(self::IMAGE, $enforcedKinds, true),
			default                 => false,
		};
	}

	/**
	 * The `marktext*` keys, or the other `mark*` keys, with values set.
	 *
	 * @param array<array-key,mixed> $params
	 *
	 * @return array<string,int|float|string>
	 */
	private static function keys(array $params, bool $text): array
	{
		$keys = [];
		foreach ($params as $key => $value) {
			$key = (string)$key;
			if (!str_starts_with($key, 'mark') || in_array($key, ['marks', 'marksettings'], true)) {
				continue;
			}

			if (str_starts_with($key, 'marktext') !== $text || !is_scalar($value) || is_bool($value) || $value === '') {
				continue;
			}

			$keys[$key] = $value;
		}

		return $keys;
	}

	/**
	 * @param array<string,int|float|string> $layer
	 * @param list<string> $keys
	 *
	 * @return array<string,int|float|string>
	 */
	private static function without(array $layer, array $keys): array
	{
		return array_diff_key($layer, array_flip($keys));
	}

	/**
	 * @param array<string,int|float|string> $layer
	 *
	 * @return array<string,int|float|string>
	 */
	private static function withDefaultWidth(array $layer): array
	{
		$layer['markw'] ??= self::DEFAULT_WIDTH;

		return $layer;
	}

	private static function filled(mixed $value): bool
	{
		return is_scalar($value) && trim((string)$value) !== '';
	}
}
