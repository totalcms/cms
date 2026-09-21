<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Typography\Data;

/**
 * The four quotation glyphs a language uses, and whether it pads them.
 *
 * Looked up by locale: an exact match (`de_CH`, `pt_PT`) wins over the
 * language (`de`, `pt`), and anything unknown is English. Extensions can add
 * or override a locale with register().
 */
final class QuoteStyle
{
	/** Narrow no-break space, as French sets it inside guillemets. Written as an entity so it is visible in source. */
	public const NARROW_NBSP = '&#8239;';

	/** @var array<string,self> */
	private static array $registry = [];

	public function __construct(
		public readonly string $openDouble,
		public readonly string $closeDouble,
		public readonly string $openSingle,
		public readonly string $closeSingle,
		public readonly bool $spaced = false,
	) {
	}

	public static function forLocale(string $locale): self
	{
		$locale   = str_replace('-', '_', trim($locale));
		$language = strtolower(explode('_', $locale)[0]);

		self::seed();

		return self::$registry[$locale]
			?? self::$registry[$language]
			?? self::$registry['en'];
	}

	/**
	 * Add or replace the style for a locale or language code. Takes effect
	 * for every later forLocale() call.
	 */
	public static function register(string $locale, self $style): void
	{
		self::seed();
		self::$registry[str_replace('-', '_', trim($locale))] = $style;
	}

	private static function seed(): void
	{
		if (self::$registry !== []) {
			return;
		}

		$english        = new self('“', '”', '‘', '’');
		$germanLowHigh  = new self('„', '“', '‚', '‘');
		$guillemets     = new self('«', '»', '‹', '›');
		$frenchSpaced   = new self('«', '»', '‹', '›', spaced: true);
		$polish         = new self('„', '”', '‚', '’');
		$scandinavian   = new self('”', '”', '’', '’');

		$table = [
			[$english,       ['en', 'nl', 'pt_BR', 'tr', 'id', 'af', 'ga', 'hi', 'bn', 'vi', 'ja', 'zh', 'ko', 'th', 'ar', 'he', 'fa']],
			[$germanLowHigh, ['de', 'cs', 'sk', 'sl', 'hr', 'bg', 'hu', 'ro', 'et', 'lt', 'lv', 'sr']],
			[$guillemets,    ['de_CH', 'it', 'es', 'pt', 'pt_PT', 'ru', 'uk', 'el', 'no', 'nb', 'nn', 'ca']],
			[$frenchSpaced,  ['fr']],
			[$polish,        ['pl']],
			[$scandinavian,  ['da', 'sv', 'fi']],
		];

		foreach ($table as [$style, $locales]) {
			foreach ($locales as $locale) {
				self::$registry[$locale] = $style;
			}
		}
	}
}
