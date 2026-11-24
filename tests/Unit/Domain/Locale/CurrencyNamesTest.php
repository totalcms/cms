<?php

declare(strict_types=1);

use TotalCMS\Domain\Locale\CurrencyNames;

/*
 * The currencies a store can be run in, for a list to choose from: each one
 * that is money somewhere today, by its name, with its code beside it.
 */

it('lists the currencies in use today, each with its name and code', function (): void {
	$options = CurrencyNames::options('en');
	$byCode  = array_column($options, 'label', 'value');

	expect($byCode['USD'])->toBe('US Dollar (USD)')
		->and($byCode['EUR'])->toBe('Euro (EUR)')
		->and($byCode['GBP'])->toBe('British Pound (GBP)')
		->and($byCode['JPY'])->toBe('Japanese Yen (JPY)')
		->and($byCode)->toHaveKey('CAD')->toHaveKey('AUD')->toHaveKey('CHF');
});

it('leaves out money that is no longer used, and codes that are not money', function (string $code): void {
	expect(array_column(CurrencyNames::options('en'), 'value'))->not->toContain($code);
})->with(['DEM', 'FRF', 'ITL', 'XXX', 'XAU']);

it('is in order of name', function (): void {
	$labels = array_column(CurrencyNames::options('en'), 'label');
	$sorted = $labels;
	sort($sorted, SORT_FLAG_CASE | SORT_STRING);

	expect($labels)->toBe($sorted)
		->and(count($labels))->toBeGreaterThan(100);
});

it('falls back to English names for a locale it has no names in', function (): void {
	expect(array_column(CurrencyNames::options('zz_ZZ'), 'label', 'value')['USD'])->toBe('US Dollar (USD)');
});
