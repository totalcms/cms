<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Locale;

use Symfony\Component\Intl\Currencies;
use Symfony\Component\Intl\Exception\MissingResourceException;

/**
 * The currencies in use today, by ISO 4217 code, with their names in any
 * locale.
 *
 * The names and what is in use come from Symfony Intl's bundled data, the
 * same data as {@see CountryNames}, so they need no PHP `intl` extension
 * and no list kept here. A store keeps the code (`USD`).
 *
 * Used by `propertyOptions: "currencies"`.
 */
final class CurrencyNames
{
	public const FALLBACK_LOCALE = 'en';

	/**
	 * Every currency that is legal tender somewhere today, as select
	 * options, sorted by name: `US Dollar (USD)`. Money that is no longer
	 * used (the German mark) and codes that are not money (gold, "no
	 * currency") are left out.
	 *
	 * @return list<array{value: string, label: string}>
	 */
	public static function options(?string $locale = null): array
	{
		$options = [];
		foreach (self::names($locale) as $code => $name) {
			$code = (string)$code;
			if (Currencies::isValidInAnyCountry($code)) {
				$options[] = ['value' => $code, 'label' => "{$name} ({$code})"];
			}
		}

		usort($options, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

		return $options;
	}

	/** @return array<array-key,string> code => name */
	private static function names(?string $locale): array
	{
		try {
			return Currencies::getNames(self::locale($locale));
		} catch (MissingResourceException) {
			return Currencies::getNames(self::FALLBACK_LOCALE);
		}
	}

	/** The locale to use: the one given, else PHP's default locale, else English. */
	private static function locale(?string $locale): string
	{
		if ($locale !== null && $locale !== '') {
			return $locale;
		}

		return class_exists(\Locale::class) ? \Locale::getDefault() : self::FALLBACK_LOCALE;
	}
}
