<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

test('price filter with default prepend format', function (): void {
	$result = TotalCMSTwigFilters::price(19.99);
	expect($result)->toBe('$19.99');

	$result2 = TotalCMSTwigFilters::price(19);
	expect($result2)->toBe('$19.00');

	$result3 = TotalCMSTwigFilters::price(0);
	expect($result3)->toBe('$0.00');
});

test('price filter with custom currency', function (): void {
	$result = TotalCMSTwigFilters::price(19.99, '€');
	expect($result)->toBe('€19.99');

	$result2 = TotalCMSTwigFilters::price(19.99, 'USD');
	expect($result2)->toBe('USD19.99');
});

test('price filter with prepend format', function (): void {
	$result = TotalCMSTwigFilters::price(19.99, '$', 'prepend');
	expect($result)->toBe('$19.99');

	$result2 = TotalCMSTwigFilters::price(19.99, 'EUR', 'prepend');
	expect($result2)->toBe('EUR19.99');
});

test('price filter with append format', function (): void {
	$result = TotalCMSTwigFilters::price(19.99, ' USD', 'append');
	expect($result)->toBe('19.99 USD');

	$result2 = TotalCMSTwigFilters::price(19.99, '€', 'append');
	expect($result2)->toBe('19.99€');
});

test('price filter with none format', function (): void {
	$result = TotalCMSTwigFilters::price(19.99, '$', 'none');
	expect($result)->toBe('19.99');

	$result2 = TotalCMSTwigFilters::price(19.99, 'EUR', 'none');
	expect($result2)->toBe('19.99');
});

test('price filter with invalid format defaults to prepend', function (): void {
	$result = TotalCMSTwigFilters::price(19.99, '$', 'invalid');
	expect($result)->toBe('$19.99');
});

test('price filter handles empty and zero values', function (): void {
	// Empty values should return empty string
	expect(TotalCMSTwigFilters::price(''))->toBe('');
	expect(TotalCMSTwigFilters::price(null))->toBe('');

	// Zero values should be formatted
	expect(TotalCMSTwigFilters::price(0))->toBe('$0.00');
	expect(TotalCMSTwigFilters::price('0'))->toBe('$0.00');
});

test('price filter handles non-numeric values', function (): void {
	$result = TotalCMSTwigFilters::price('not a number');
	expect($result)->toBe('$0.00');

	$result2 = TotalCMSTwigFilters::price('abc123');
	expect($result2)->toBe('$0.00');
});

test('price filter formats decimals correctly', function (): void {
	$result = TotalCMSTwigFilters::price(19.999);
	expect($result)->toBe('$20.00'); // Should round to 2 decimal places

	$result2 = TotalCMSTwigFilters::price(19.1);
	expect($result2)->toBe('$19.10');

	$result3 = TotalCMSTwigFilters::price(19);
	expect($result3)->toBe('$19.00');
});

test('price filter works with string numbers', function (): void {
	$result = TotalCMSTwigFilters::price('19.99');
	expect($result)->toBe('$19.99');

	$result2 = TotalCMSTwigFilters::price('19');
	expect($result2)->toBe('$19.00');
});
