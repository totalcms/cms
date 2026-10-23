<?php

declare(strict_types=1);

use TotalCMS\Domain\Locale\CountryNames;

it('names a country in the given locale', function (): void {
	expect(CountryNames::name('US', 'en'))->toBe('United States');
	expect(CountryNames::name('US', 'de_DE'))->toBe('Vereinigte Staaten');
	expect(CountryNames::name('jp', 'fr'))->toBe('Japon');
});

it('returns an unknown code unchanged and empty input as empty', function (): void {
	expect(CountryNames::name('ZZ', 'en'))->toBe('ZZ');
	expect(CountryNames::name('United States', 'en'))->toBe('United States');
	expect(CountryNames::name('', 'en'))->toBe('');
	expect(CountryNames::name(null, 'en'))->toBe('');
});

it('falls back to English for a locale it has no data for', function (): void {
	expect(CountryNames::name('US', 'xx_NOPE'))->toBe('United States');
	expect(CountryNames::options('xx_NOPE'))->toBe(CountryNames::options('en'));
});

it('lists every country as code/name options sorted by name in the locale', function (): void {
	$options = CountryNames::options('de');

	expect(count($options))->toBeGreaterThan(240);
	expect($options)->toContain(['value' => 'DE', 'label' => 'Deutschland']);
	if (!class_exists(Collator::class)) {
		return;
	}
	$labels   = array_column($options, 'label');
	$sorted   = $labels;
	$collator = new Collator('de');
	$collator->sort($sorted);
	expect($labels)->toBe($sorted);
});
