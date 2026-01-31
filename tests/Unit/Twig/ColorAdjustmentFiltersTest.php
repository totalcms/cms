<?php

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

// ===== lightness =====

test('lightness adjusts color lightness', function (): void {
	$color  = ['oklch' => ['l' => 0.5, 'c' => 0.1, 'h' => 200], 'hex' => '#336699'];
	$result = TotalCMSTwigFilters::lightness($color, '+20');
	expect($result)->toBeArray();
	expect($result)->toHaveKeys(['oklch', 'hex']);
	expect($result['oklch']['l'])->not->toBe(0.5);
});

test('lightness returns null for null color', function (): void {
	expect(TotalCMSTwigFilters::lightness(null, '+10'))->toBeNull();
});

test('lightness decreases lightness', function (): void {
	$color  = ['oklch' => ['l' => 0.7, 'c' => 0.1, 'h' => 200], 'hex' => '#336699'];
	$result = TotalCMSTwigFilters::lightness($color, '-20');
	expect($result)->toBeArray();
	expect($result['oklch']['l'])->toBeLessThan(0.7);
});

// ===== chroma =====

test('chroma adjusts color saturation', function (): void {
	$color  = ['oklch' => ['l' => 0.5, 'c' => 0.1, 'h' => 200], 'hex' => '#336699'];
	$result = TotalCMSTwigFilters::chroma($color, '+0.05');
	expect($result)->toBeArray();
	expect($result)->toHaveKeys(['oklch', 'hex']);
});

test('chroma returns null for null color', function (): void {
	expect(TotalCMSTwigFilters::chroma(null, '+10'))->toBeNull();
});

test('chroma decreases saturation', function (): void {
	$color  = ['oklch' => ['l' => 0.5, 'c' => 0.2, 'h' => 200], 'hex' => '#336699'];
	$result = TotalCMSTwigFilters::chroma($color, '-0.05');
	expect($result)->toBeArray();
	expect($result['oklch']['c'])->toBeLessThan(0.2);
});

// ===== hue =====

test('hue adjusts color hue', function (): void {
	$color  = ['oklch' => ['l' => 0.5, 'c' => 0.1, 'h' => 200], 'hex' => '#336699'];
	$result = TotalCMSTwigFilters::hue($color, '+30');
	expect($result)->toBeArray();
	expect($result)->toHaveKeys(['oklch', 'hex']);
	expect($result['oklch']['h'])->not->toBe(200);
});

test('hue returns null for null color', function (): void {
	expect(TotalCMSTwigFilters::hue(null, '+30'))->toBeNull();
});

test('hue rotates by 180 for complement', function (): void {
	$color  = ['oklch' => ['l' => 0.5, 'c' => 0.1, 'h' => 100], 'hex' => '#336699'];
	$result = TotalCMSTwigFilters::hue($color, '+180');
	expect($result)->toBeArray();
	expect($result['oklch']['h'])->toBe(280.0);
});

// ===== adjustColor =====

test('adjustColor adjusts multiple properties at once', function (): void {
	$color  = ['oklch' => ['l' => 0.5, 'c' => 0.1, 'h' => 200], 'hex' => '#336699'];
	$result = TotalCMSTwigFilters::adjustColor($color, '+10', '+0.05', '+30');
	expect($result)->toBeArray();
	expect($result)->toHaveKeys(['oklch', 'hex']);
});

test('adjustColor returns null for null color', function (): void {
	expect(TotalCMSTwigFilters::adjustColor(null))->toBeNull();
});

test('adjustColor with only lightness preserves other values', function (): void {
	$color  = ['oklch' => ['l' => 0.5, 'c' => 0.1, 'h' => 200], 'hex' => '#336699'];
	$result = TotalCMSTwigFilters::adjustColor($color, '+10');
	expect($result)->toBeArray();
	expect($result['oklch']['c'])->toBe(0.1);
	expect($result['oklch']['h'])->toBe(200);
});

test('adjustColor returns valid hex string', function (): void {
	$color  = ['oklch' => ['l' => 0.5, 'c' => 0.1, 'h' => 200], 'hex' => '#336699'];
	$result = TotalCMSTwigFilters::adjustColor($color, '+10');
	expect($result['hex'])->toBeString();
	expect($result['hex'])->toStartWith('#');
});
