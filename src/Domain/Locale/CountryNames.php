<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Locale;

use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Exception\MissingResourceException;

/**
 * Country names by ISO 3166-1 alpha-2 code, in any locale.
 *
 * The names come from symfony/intl's bundled CLDR data, the same source as
 * Twig's `country_name` filter, so they need no PHP `intl` extension and no
 * list kept here. A store keeps the code (`US`); a page shows the name in
 * the reader's locale.
 *
 * Used by `propertyOptions: "countries"` and by the `country_name` filter
 * core registers for servers where Twig's IntlExtension is not loaded.
 */
final class CountryNames
{
	public const FALLBACK_LOCALE = 'en';

	/**
	 * Every country as select options, sorted by name in the locale.
	 *
	 * @return list<array{value: string, label: string}>
	 */
	public static function options(?string $locale = null): array
	{
		$options = [];
		foreach (self::names($locale) as $code => $name) {
			$options[] = ['value' => $code, 'label' => $name];
		}

		return $options;
	}

	/**
	 * The country's name in the locale; '' for null or empty, and the code
	 * unchanged when it is not a country.
	 */
	public static function name(?string $code, ?string $locale = null): string
	{
		if ($code === null || $code === '') {
			return '';
		}

		$upper = strtoupper($code);
		if (!Countries::exists($upper)) {
			return $code;
		}

		try {
			return Countries::getName($upper, self::locale($locale));
		} catch (MissingResourceException) {
			return Countries::getName($upper, self::FALLBACK_LOCALE);
		}
	}

	/** @return array<string,string> code => name, sorted by name */
	private static function names(?string $locale): array
	{
		try {
			return Countries::getNames(self::locale($locale));
		} catch (MissingResourceException) {
			return Countries::getNames(self::FALLBACK_LOCALE);
		}
	}

	/**
	 * The locale to use: the one given, else PHP's default locale (which
	 * TwigEngine sets from the site's locale when `intl` is loaded), else
	 * English.
	 */
	private static function locale(?string $locale): string
	{
		if ($locale !== null && $locale !== '') {
			return $locale;
		}

		return class_exists(\Locale::class) ? \Locale::getDefault() : self::FALLBACK_LOCALE;
	}
}
