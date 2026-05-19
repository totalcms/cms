<?php

declare(strict_types=1);

use TotalCMS\Domain\Locale\LocaleRegistry;

describe('LocaleRegistry static lookups', function (): void {
	test('has() reports membership', function (): void {
		expect(LocaleRegistry::has('en_US'))->toBeTrue();
		expect(LocaleRegistry::has('de'))->toBeTrue();
		expect(LocaleRegistry::has('ar_SA'))->toBeTrue();
		expect(LocaleRegistry::has('xx_XX'))->toBeFalse();
		expect(LocaleRegistry::has(''))->toBeFalse();
	});

	test('meta() returns label + english + dir for known codes', function (): void {
		$de = LocaleRegistry::meta('de_DE');

		expect($de)->toBeArray();
		expect($de['label'])->toBe('Deutsch (DE)');
		expect($de['english'])->toBe('German (Germany)');
		expect($de['dir'])->toBe('ltr');

		$ar = LocaleRegistry::meta('ar');
		expect($ar['dir'])->toBe('rtl');
	});

	test('meta() returns null for unknown codes', function (): void {
		expect(LocaleRegistry::meta('xx_XX'))->toBeNull();
	});

	test('options() formats codes as Native [code] suitable for selects', function (): void {
		$opts = LocaleRegistry::options();
		expect($opts)->toBeArray();
		expect(count($opts))->toBeGreaterThan(40);

		// Pick a known entry and check shape
		$en_US = array_values(array_filter($opts, fn ($o) => $o['value'] === 'en_US'))[0] ?? null;
		expect($en_US)->not->toBeNull();
		expect($en_US['label'])->toBe('English (US) [en_US]');
	});
});

describe('LocaleRegistry::expand (strict)', function (): void {
	test('flat code list expands to dict-of-dicts', function (): void {
		$out = LocaleRegistry::expand(['en_US', 'de', 'ar']);

		expect($out)->toHaveCount(3);
		expect($out[0])->toBe(['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr']);
		expect($out[1])->toBe(['code' => 'de',    'label' => 'Deutsch',      'dir' => 'ltr']);
		expect($out[2])->toBe(['code' => 'ar',    'label' => 'العربية',      'dir' => 'rtl']);
	});

	test('unknown codes are silently dropped, order preserved', function (): void {
		$out = LocaleRegistry::expand(['en_US', 'xx_XX', 'de']);

		expect($out)->toHaveCount(2);
		expect($out[0]['code'])->toBe('en_US');
		expect($out[1]['code'])->toBe('de');
	});

	test('empty list returns empty', function (): void {
		expect(LocaleRegistry::expand([]))->toBe([]);
	});
});

describe('LocaleRegistry::normalize (lenient)', function (): void {
	test('accepts a flat code list and delegates to expand()', function (): void {
		$out = LocaleRegistry::normalize(['en_US', 'de']);

		expect($out)->toHaveCount(2);
		expect($out[0]['code'])->toBe('en_US');
		expect($out[0]['label'])->toBe('English (US)');
	});

	test('accepts pre-expanded dict-of-dicts and passes through with coercion', function (): void {
		$out = LocaleRegistry::normalize([
			['code' => 'en_US', 'label' => 'Custom English', 'dir' => 'ltr'],
			['code' => 'de',    'label' => 'Custom German',  'dir' => 'ltr'],
		]);

		expect($out)->toHaveCount(2);
		// Pre-expanded input is passed through — registry labels do NOT override
		// operator-supplied labels in the legacy shape.
		expect($out[0]['label'])->toBe('Custom English');
		expect($out[1]['label'])->toBe('Custom German');
	});

	test('pre-expanded dicts missing label fall back to code', function (): void {
		$out = LocaleRegistry::normalize([
			['code' => 'en_US'],
		]);

		expect($out)->toHaveCount(1);
		expect($out[0]['label'])->toBe('en_US');
		expect($out[0]['dir'])->toBe('ltr');
	});

	test('pre-expanded dicts missing code are dropped', function (): void {
		$out = LocaleRegistry::normalize([
			['code' => 'en_US', 'label' => 'EN'],
			['label' => 'no code here'],
			['code' => 'de'],
		]);

		expect($out)->toHaveCount(2);
		expect($out[0]['code'])->toBe('en_US');
		expect($out[1]['code'])->toBe('de');
	});

	test('non-array input returns empty', function (): void {
		expect(LocaleRegistry::normalize(null))->toBe([]);
		expect(LocaleRegistry::normalize('en_US'))->toBe([]);
		expect(LocaleRegistry::normalize(42))->toBe([]);
	});

	test('empty array returns empty', function (): void {
		expect(LocaleRegistry::normalize([]))->toBe([]);
	});
});
