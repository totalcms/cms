<?php

declare(strict_types=1);

use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\LocaleTwigAdapter;
use TotalCMS\Support\Config;

/**
 * Build a LocaleTwigAdapter with a Config containing the given locale list.
 *
 * Config::__construct expects a full settings array; using
 * newInstanceWithoutConstructor and assigning the few properties we touch
 * (per CLAUDE.md guidance) keeps the test isolated from the rest of the
 * config machinery. TranslationService isn't touched by the new helpers,
 * so a bare instance is fine.
 *
 * @param array<int,array<string,string>> $locales
 */
function makeLocaleAdapter(array $locales = []): LocaleTwigAdapter
{
	$configRef = new ReflectionClass(Config::class);
	$config    = $configRef->newInstanceWithoutConstructor();
	$config->i18n = [
		'default'   => $locales[0]['code'] ?? '',
		'available' => $locales,
	];

	$translatorRef = new ReflectionClass(TranslationService::class);
	$translator    = $translatorRef->newInstanceWithoutConstructor();

	return new LocaleTwigAdapter($translator, $config);
}

describe('LocaleTwigAdapter::canonicalizeLocale', function (): void {
	test('lowercases language, uppercases region', function (): void {
		expect(LocaleTwigAdapter::canonicalizeLocale('en_US'))->toBe('en_US');
		expect(LocaleTwigAdapter::canonicalizeLocale('en_us'))->toBe('en_US');
		expect(LocaleTwigAdapter::canonicalizeLocale('EN_US'))->toBe('en_US');
		expect(LocaleTwigAdapter::canonicalizeLocale('En_Us'))->toBe('en_US');
	});

	test('accepts dash separator and normalizes to underscore', function (): void {
		expect(LocaleTwigAdapter::canonicalizeLocale('en-US'))->toBe('en_US');
		expect(LocaleTwigAdapter::canonicalizeLocale('pt-br'))->toBe('pt_BR');
	});

	test('leaves bare language codes lowercase', function (): void {
		expect(LocaleTwigAdapter::canonicalizeLocale('de'))->toBe('de');
		expect(LocaleTwigAdapter::canonicalizeLocale('DE'))->toBe('de');
		expect(LocaleTwigAdapter::canonicalizeLocale('Fr'))->toBe('fr');
	});

	test('returns empty for empty input', function (): void {
		expect(LocaleTwigAdapter::canonicalizeLocale(''))->toBe('');
		expect(LocaleTwigAdapter::canonicalizeLocale('   '))->toBe('');
	});
});

describe('LocaleTwigAdapter::text — exact match', function (): void {
	test('returns exact match for canonical locale', function (): void {
		$adapter = makeLocaleAdapter();
		$value   = ['en_US' => 'About', 'de' => 'Über'];

		expect($adapter->text($value, 'en_US'))->toBe('About');
		expect($adapter->text($value, 'de'))->toBe('Über');
	});

	test('case-insensitive on locale arg', function (): void {
		$adapter = makeLocaleAdapter();
		$value   = ['en_US' => 'About'];

		expect($adapter->text($value, 'en_us'))->toBe('About');
		expect($adapter->text($value, 'EN_US'))->toBe('About');
		expect($adapter->text($value, 'En_Us'))->toBe('About');
	});

	test('accepts dashed input form', function (): void {
		$adapter = makeLocaleAdapter();
		$value   = ['en_US' => 'About'];

		expect($adapter->text($value, 'en-US'))->toBe('About');
	});
});

describe('LocaleTwigAdapter::text — fallback chain', function (): void {
	test('region fall-up: de_DE → de', function (): void {
		$adapter = makeLocaleAdapter();
		$value   = ['en_US' => 'About', 'de' => 'Über'];

		expect($adapter->text($value, 'de_DE'))->toBe('Über');
	});

	test('region fall-down: en → first matching en_* in configured order', function (): void {
		$adapter = makeLocaleAdapter([
			['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
			['code' => 'en_GB', 'label' => 'English (UK)', 'dir' => 'ltr'],
		]);
		$value = ['en_US' => 'About US', 'en_GB' => 'About UK'];

		expect($adapter->text($value, 'en'))->toBe('About US');
	});

	test('region fall-down respects configured order', function (): void {
		$adapter = makeLocaleAdapter([
			['code' => 'en_GB', 'label' => 'English (UK)', 'dir' => 'ltr'],
			['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
		]);
		$value = ['en_US' => 'About US', 'en_GB' => 'About UK'];

		expect($adapter->text($value, 'en'))->toBe('About UK');
	});

	test('region fall-down falls back to dict insertion order when no configured locales', function (): void {
		$adapter = makeLocaleAdapter();
		$value   = ['en_GB' => 'About UK', 'en_US' => 'About US'];

		expect($adapter->text($value, 'en'))->toBe('About UK');
	});

	test('returns empty string when no fallback can be found', function (): void {
		$adapter = makeLocaleAdapter();
		$value   = ['en_US' => 'About'];

		expect($adapter->text($value, 'fr'))->toBe('');
		expect($adapter->text($value, 'ja_JP'))->toBe('');
	});
});

describe('LocaleTwigAdapter::text — defensive input', function (): void {
	test('returns empty for non-array value', function (): void {
		$adapter = makeLocaleAdapter();

		expect($adapter->text('plain string', 'de'))->toBe('');
		expect($adapter->text(null, 'de'))->toBe('');
		expect($adapter->text(42, 'de'))->toBe('');
	});

	test('returns empty for empty dict', function (): void {
		$adapter = makeLocaleAdapter();

		expect($adapter->text([], 'de'))->toBe('');
	});

	test('returns empty for empty locale arg', function (): void {
		$adapter = makeLocaleAdapter();
		$value   = ['en_US' => 'About'];

		expect($adapter->text($value, ''))->toBe('');
	});
});

describe('LocaleTwigAdapter::styledtext', function (): void {
	test('mirrors text() behavior', function (): void {
		$adapter = makeLocaleAdapter();
		$value   = ['en_US' => '<p>About</p>', 'de' => '<p>Über</p>'];

		expect($adapter->styledtext($value, 'de'))->toBe('<p>Über</p>');
		expect($adapter->styledtext($value, 'fr'))->toBe('');
	});
});
