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
		$en_US = array_values(array_filter($opts, fn (array $o): bool => $o['value'] === 'en_US'))[0] ?? null;
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
			['code'  => 'de'],
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

describe('EU official languages', function (): void {
	// Total CMS is used by EU public-sector organizations, where the 24
	// official languages of the Union are the working set. Nine were missing
	// until 2026-09-19 — Croatia, Slovakia and Slovenia could not pick their
	// own language at all — and the registry is deliberately not
	// operator-extensible, so a gap here is a hard stop for that customer
	// rather than something they can configure around.
	test('every one of the 24 official EU languages has at least one code', function (): void {
		$official = [
			'bg' => 'Bulgarian', 'hr' => 'Croatian',   'cs' => 'Czech',      'da' => 'Danish',
			'nl' => 'Dutch',     'en' => 'English',    'et' => 'Estonian',   'fi' => 'Finnish',
			'fr' => 'French',    'de' => 'German',     'el' => 'Greek',      'hu' => 'Hungarian',
			'ga' => 'Irish',     'it' => 'Italian',    'lv' => 'Latvian',    'lt' => 'Lithuanian',
			'mt' => 'Maltese',   'pl' => 'Polish',     'pt' => 'Portuguese', 'ro' => 'Romanian',
			'sk' => 'Slovak',    'sl' => 'Slovenian',  'es' => 'Spanish',    'sv' => 'Swedish',
		];

		$codes   = array_keys(LocaleRegistry::LOCALES);
		$missing = [];
		foreach ($official as $prefix => $language) {
			$found = array_filter(
				$codes,
				static fn (string $c): bool => $c === $prefix || str_starts_with($c, $prefix . '_'),
			);
			if ($found === []) {
				$missing[] = "$language ($prefix)";
			}
		}

		expect($missing)->toBe([]);
	});

	test('the nine added for the EU carry a native label and a direction', function (): void {
		$added = ['bg_BG', 'et_EE', 'ga_IE', 'hr_HR', 'lt_LT', 'lv_LV', 'mt_MT', 'sk_SK', 'sl_SI'];

		foreach ($added as $code) {
			$meta = LocaleRegistry::meta($code);
			expect($meta)->not->toBeNull("missing registry entry for $code");
			expect($meta['label'])->not->toBe('');
			expect($meta['english'])->not->toBe('');
			expect($meta['dir'])->toBe('ltr');
		}
	});

	test('Slovak and Slovenian are not the same string', function (): void {
		// Slovenčina vs Slovenščina differ by one character cluster and read
		// like a typo of each other. Pin them so a "fix" cannot collapse them.
		expect(LocaleRegistry::meta('sk_SK')['label'])->toBe('Slovenčina');
		expect(LocaleRegistry::meta('sl_SI')['label'])->toBe('Slovenščina');
	});
});

describe('the Supported Locales docs page', function (): void {
	// The class docblock names this page as a consumer of the registry, and it
	// is the only place the full list is published. Nothing kept them in sync.
	test('lists exactly the codes the registry ships', function (): void {
		$page = file_get_contents(dirname(__DIR__, 3) . '/resources/docs/operations/supported-locales.md');
		expect($page)->not->toBeFalse();

		preg_match_all('/^\| `([A-Za-z_]+)` \|/m', (string)$page, $m);
		$documented = $m[1];

		sort($documented);
		$registry = array_keys(LocaleRegistry::LOCALES);
		sort($registry);

		expect(array_values(array_diff($registry, $documented)))->toBe([], 'codes in the registry but not the docs table');
		expect(array_values(array_diff($documented, $registry)))->toBe([], 'codes in the docs table but not the registry');
	});
});
