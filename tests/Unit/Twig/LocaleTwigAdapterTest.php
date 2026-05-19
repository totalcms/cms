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

describe('LocaleTwigAdapter::text — site-default fallback', function (): void {
	test('falls back to i18n.default when the requested locale is missing', function (): void {
		// Site default is en_US (the first in the configured list — see
		// makeLocaleAdapter()). A French request that the dict can\'t satisfy
		// should reach for en_US as the last-resort step before empty.
		$adapter = makeLocaleAdapter([
			['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
			['code' => 'de',    'label' => 'Deutsch',      'dir' => 'ltr'],
		]);
		$value = ['en_US' => 'Hello'];

		expect($adapter->text($value, 'fr'))->toBe('Hello');
		expect($adapter->text($value, 'ja_JP'))->toBe('Hello');
	});

	test('site-default fallback does not loop when the request is the default', function (): void {
		$adapter = makeLocaleAdapter([
			['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
		]);
		// dict has no en_US, no en, nothing. Requesting en_US (which IS the
		// site default) should NOT recurse back into the default — it should
		// give up and return empty.
		expect($adapter->text(['xx_XX' => 'orphan'], 'en_US'))->toBe('');
	});

	test('returns empty when no site default is configured and no fallback path matches', function (): void {
		$adapter = makeLocaleAdapter();  // no available locales, no default
		$value   = ['en_US' => 'Hello'];

		expect($adapter->text($value, 'fr'))->toBe('');
	});

	test('region fall-up still runs before site-default fallback', function (): void {
		// Make sure adding step 5 didn\'t short-circuit step 3.
		$adapter = makeLocaleAdapter([
			['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
			['code' => 'de',    'label' => 'Deutsch',      'dir' => 'ltr'],
		]);
		$value = ['en_US' => 'Hello', 'de' => 'Hallo'];

		// Request `de_DE` — should fall UP to `de` (Hallo), not to the
		// site default (en_US's Hello).
		expect($adapter->text($value, 'de_DE'))->toBe('Hallo');
	});
});

describe('LocalizedtextData REST serialization shape', function (): void {
	test('transform() returns the full locale-keyed dict (no resolution)', function (): void {
		// The REST API path uses `ObjectData::toArray()` which calls
		// `transform()` on every PropertyData object. For localized fields
		// the contract is "always return the full multi-locale object" —
		// no server-side resolution to a single locale in 3.5. This test
		// is the contract guard.
		$data = new TotalCMS\Domain\Property\Data\LocalizedtextData([
			'en_US' => 'Welcome',
			'de'    => 'Willkommen',
			'ar'    => 'أهلا بك',
		]);

		$out = $data->transform();
		expect($out)->toBe([
			'en_US' => 'Welcome',
			'de'    => 'Willkommen',
			'ar'    => 'أهلا بك',
		]);

		// json_encode round-trip = what the REST response body looks like.
		$json = (string)json_encode($out, JSON_UNESCAPED_UNICODE);
		expect($json)->toContain('"en_US":"Welcome"');
		expect($json)->toContain('"de":"Willkommen"');
		expect($json)->toContain('"ar":"أهلا بك"');
	});

	test('transform() preserves empty-string locales (no value dropping)', function (): void {
		// A locale that's been authored as empty should round-trip as empty —
		// downstream callers may treat "" differently from "key absent",
		// and the REST shape must distinguish them.
		$data = new TotalCMS\Domain\Property\Data\LocalizedtextData([
			'en_US' => 'Welcome',
			'de'    => '',
			'ar'    => '',
		]);

		expect($data->transform())->toBe([
			'en_US' => 'Welcome',
			'de'    => '',
			'ar'    => '',
		]);
	});
});
