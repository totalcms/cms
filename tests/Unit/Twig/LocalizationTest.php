<?php

declare(strict_types=1);

use Cake\Chronos\Chronos;
use Cake\I18n\I18n;
use Cake\I18n\RelativeTimeFormatter;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

beforeEach(function (): void {
	// Reset locale to English before each test
	Locale::setDefault('en_US');
	I18n::setLocale('en_US');
	// Initialize the RelativeTimeFormatter for Chronos (as done in TwigEngine)
	Chronos::diffFormatter(new RelativeTimeFormatter());
});

afterEach(function (): void {
	// Reset locale after each test to avoid affecting other tests
	Locale::setDefault('en_US');
	I18n::setLocale('en_US');
});

describe('Localization', function (): void {
	// -------------------------
	// Locale Setting
	// -------------------------

	test('setLocale updates PHP Locale', function (): void {
		Locale::setDefault('de_DE');

		expect(Locale::getDefault())->toBe('de_DE');
	});

	test('setLocale updates CakePHP I18n locale', function (): void {
		I18n::setLocale('fr_FR');

		expect(I18n::getLocale())->toBe('fr_FR');
	});

	test('locale can be changed multiple times', function (): void {
		Locale::setDefault('en_US');
		I18n::setLocale('en_US');
		expect(I18n::getLocale())->toBe('en_US');

		Locale::setDefault('de_DE');
		I18n::setLocale('de_DE');
		expect(I18n::getLocale())->toBe('de_DE');

		Locale::setDefault('ja_JP');
		I18n::setLocale('ja_JP');
		expect(I18n::getLocale())->toBe('ja_JP');
	});

	// -------------------------
	// dateRelative with English
	// -------------------------

	test('dateRelative returns English strings by default', function (): void {
		$pastDate = date('Y-m-d', strtotime('-2 months'));
		$result   = TotalCMSTwigFilters::dateRelative($pastDate);

		expect($result)->toContain('ago');
		expect($result)->toMatch('/month|months/');
	});

	test('dateRelative handles future dates in English', function (): void {
		$futureDate = date('Y-m-d', strtotime('+1 week'));
		$result     = TotalCMSTwigFilters::dateRelative($futureDate);

		// Future dates should show "from now" or similar
		expect($result)->toMatch('/from now|in/i');
	});

	// -------------------------
	// dateRelative with German
	// -------------------------

	test('dateRelative returns German strings when locale is de_DE', function (): void {
		Locale::setDefault('de_DE');
		I18n::setLocale('de_DE');

		$pastDate = date('Y-m-d', strtotime('-2 months'));
		$result   = TotalCMSTwigFilters::dateRelative($pastDate);

		// German relative time should contain "vor" (ago) and "Monat" (month)
		expect($result)->toContain('vor');
		expect($result)->toMatch('/Monat|Monaten/');
	});

	test('dateRelative handles days in German', function (): void {
		Locale::setDefault('de_DE');
		I18n::setLocale('de_DE');

		$pastDate = date('Y-m-d', strtotime('-3 days'));
		$result   = TotalCMSTwigFilters::dateRelative($pastDate);

		expect($result)->toContain('vor');
		expect($result)->toMatch('/Tag|Tagen/');
	});

	// -------------------------
	// dateRelative with French
	// -------------------------

	test('dateRelative returns French strings when locale is fr_FR', function (): void {
		Locale::setDefault('fr_FR');
		I18n::setLocale('fr_FR');

		$pastDate = date('Y-m-d', strtotime('-2 months'));
		$result   = TotalCMSTwigFilters::dateRelative($pastDate);

		// French relative time should contain "il y a" (ago)
		expect($result)->toContain('il y a');
		expect($result)->toMatch('/mois/');
	});

	// -------------------------
	// dateRelative with Spanish
	// -------------------------

	test('dateRelative returns Spanish strings when locale is es_ES', function (): void {
		Locale::setDefault('es_ES');
		I18n::setLocale('es_ES');

		$pastDate = date('Y-m-d', strtotime('-2 months'));
		$result   = TotalCMSTwigFilters::dateRelative($pastDate);

		// Spanish relative time should contain "hace" (ago)
		expect($result)->toContain('hace');
		expect($result)->toMatch('/mes|meses/');
	});

	// -------------------------
	// dateRelative with Japanese
	// -------------------------

	test('dateRelative returns Japanese strings when locale is ja_JP', function (): void {
		Locale::setDefault('ja_JP');
		I18n::setLocale('ja_JP');

		$pastDate = date('Y-m-d', strtotime('-2 months'));
		$result   = TotalCMSTwigFilters::dateRelative($pastDate);

		// Japanese relative time should contain "前" (ago) and "か月" (months)
		expect($result)->toContain('前');
		expect($result)->toMatch('/か月/');
	});

	// -------------------------
	// dateRelative Edge Cases
	// -------------------------

	test('dateRelative handles invalid dates gracefully', function (): void {
		$result = TotalCMSTwigFilters::dateRelative('not-a-date');

		expect($result)->toBe('not-a-date');
	});

	test('dateRelative handles empty string as now', function (): void {
		// Empty string parses as "now" which results in "0 seconds ago"
		$result = TotalCMSTwigFilters::dateRelative('');

		expect($result)->toContain('seconds ago');
	});

	test('dateRelative handles null as now', function (): void {
		// Null parses as "now" which results in "0 seconds ago"
		$result = TotalCMSTwigFilters::dateRelative(null);

		expect($result)->toContain('seconds ago');
	});

	test('dateRelative handles various date formats', function (): void {
		// ISO 8601
		$result1 = TotalCMSTwigFilters::dateRelative('2024-01-15T12:00:00+00:00');
		expect($result1)->toBeString();
		expect($result1)->not->toBe('2024-01-15T12:00:00+00:00');

		// Unix timestamp
		$result2 = TotalCMSTwigFilters::dateRelative(strtotime('-1 week'));
		expect($result2)->toBeString();
		expect($result2)->toContain('ago');

		// Relative strings
		$result3 = TotalCMSTwigFilters::dateRelative('yesterday');
		expect($result3)->toBeString();
		expect($result3)->toContain('ago');
	});

	// -------------------------
	// dateDiff Localization
	// -------------------------

	test('dateDiff returns localized strings', function (): void {
		$date1 = date('Y-m-d', strtotime('-3 months'));
		$date2 = date('Y-m-d');

		// Test English
		Locale::setDefault('en_US');
		I18n::setLocale('en_US');
		$resultEn = TotalCMSTwigFilters::dateDiff($date1, $date2);
		expect($resultEn)->toBeString();
		expect($resultEn)->not->toBeEmpty();

		// Test German
		Locale::setDefault('de_DE');
		I18n::setLocale('de_DE');
		$resultDe = TotalCMSTwigFilters::dateDiff($date1, $date2);
		expect($resultDe)->toBeString();
		expect($resultDe)->not->toBeEmpty();
	});

	// -------------------------
	// Supported Locales
	// -------------------------

	test('all supported locales can be set without error', function (): void {
		$supportedLocales = [
			'en_US', 'en_GB', 'en_CA', 'en_AU', 'en_SG',
			'ar_SA', 'cs_CZ', 'da_DK', 'de_DE',
			'es_ES', 'es_MX', 'fr_FR', 'fr_CA',
			'hu_HU', 'it_IT', 'ja_JP', 'km_KH',
			'nl_NL', 'no_NO', 'pl_PL', 'pt_BR', 'pt_PT',
			'ru_RU', 'tr_TR', 'uk_UA', 'vi_VN', 'zh_CN',
		];

		foreach ($supportedLocales as $locale) {
			Locale::setDefault($locale);
			I18n::setLocale($locale);

			expect(Locale::getDefault())->toBe($locale);
			expect(I18n::getLocale())->toBe($locale);
		}
	});

	test('dateRelative works with all supported locales', function (): void {
		$supportedLocales = [
			'en_US', 'de_DE', 'fr_FR', 'es_ES', 'ja_JP',
			'it_IT', 'pt_BR', 'nl_NL', 'pl_PL', 'ru_RU',
		];

		$pastDate = date('Y-m-d', strtotime('-2 months'));

		foreach ($supportedLocales as $locale) {
			Locale::setDefault($locale);
			I18n::setLocale($locale);

			$result = TotalCMSTwigFilters::dateRelative($pastDate);

			expect($result)->toBeString();
			expect($result)->not->toBeEmpty();
			expect($result)->not->toBe($pastDate); // Should be transformed
		}
	});

	// -------------------------
	// Number Formatting via IntlExtension
	// -------------------------

	test('number formatting respects locale', function (): void {
		// This tests PHP's NumberFormatter which is used by IntlExtension
		$number = 1234567.89;

		// English (US)
		Locale::setDefault('en_US');
		$formatter = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
		$resultUs  = $formatter->format($number);
		expect($resultUs)->toBe('1,234,567.89');

		// German
		Locale::setDefault('de_DE');
		$formatter = new NumberFormatter('de_DE', NumberFormatter::DECIMAL);
		$resultDe  = $formatter->format($number);
		expect($resultDe)->toBe('1.234.567,89');

		// French - uses narrow no-break space (U+202F) as thousands separator
		Locale::setDefault('fr_FR');
		$formatter = new NumberFormatter('fr_FR', NumberFormatter::DECIMAL);
		$resultFr  = $formatter->format($number);
		// Normalize whitespace for comparison (narrow no-break space)
		$normalizedFr = preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $resultFr);
		expect($normalizedFr)->toBe('1 234 567,89');
	});

	test('currency formatting respects locale', function (): void {
		$amount = 99.99;

		// English (US) formatting USD
		Locale::setDefault('en_US');
		$formatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
		$resultUs  = $formatter->formatCurrency($amount, 'USD');
		expect($resultUs)->toContain('$');
		expect($resultUs)->toContain('99.99');

		// German formatting EUR
		Locale::setDefault('de_DE');
		$formatter = new NumberFormatter('de_DE', NumberFormatter::CURRENCY);
		$resultDe  = $formatter->formatCurrency($amount, 'EUR');
		expect($resultDe)->toContain('€');
		expect($resultDe)->toContain('99,99');
	});

	test('date formatting respects locale', function (): void {
		$timestamp = strtotime('2024-12-31 15:30:00');

		// English (US)
		Locale::setDefault('en_US');
		$formatter = new IntlDateFormatter(
			'en_US',
			IntlDateFormatter::LONG,
			IntlDateFormatter::NONE
		);
		$resultUs = $formatter->format($timestamp);
		expect($resultUs)->toBe('December 31, 2024');

		// German
		Locale::setDefault('de_DE');
		$formatter = new IntlDateFormatter(
			'de_DE',
			IntlDateFormatter::LONG,
			IntlDateFormatter::NONE
		);
		$resultDe = $formatter->format($timestamp);
		expect($resultDe)->toBe('31. Dezember 2024');
	});

	// -------------------------
	// Country/Language Names
	// -------------------------

	test('country names are localized', function (): void {
		// English
		Locale::setDefault('en_US');
		$usInEnglish = Locale::getDisplayRegion('en_US', 'en_US');
		expect($usInEnglish)->toBe('United States');

		// German
		Locale::setDefault('de_DE');
		$usInGerman = Locale::getDisplayRegion('en_US', 'de_DE');
		expect($usInGerman)->toBe('Vereinigte Staaten');
	});

	test('language names are localized', function (): void {
		// English
		Locale::setDefault('en_US');
		$germanInEnglish = Locale::getDisplayLanguage('de_DE', 'en_US');
		expect($germanInEnglish)->toBe('German');

		// German
		Locale::setDefault('de_DE');
		$germanInGerman = Locale::getDisplayLanguage('de_DE', 'de_DE');
		expect($germanInGerman)->toBe('Deutsch');
	});
});
