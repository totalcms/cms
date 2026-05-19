<?php

declare(strict_types=1);

use TotalCMS\Domain\Property\Data\ColorData;

// ===== Constructor: string inputs =====

test('constructor accepts 6-digit hex string', function (): void {
	$color = new ColorData('#ff0000');
	expect($color->hex)->toBe('#ff0000');
	expect($color->oklch)->toBeArray()->toHaveKeys(['l', 'c', 'h']);
});

test('constructor accepts 3-digit short hex string', function (): void {
	$color = new ColorData('#f00');
	expect($color->hex)->toBe('#f00');
	expect($color->oklch)->toBeArray();
});

test('constructor accepts hex without leading hash', function (): void {
	$color = new ColorData('ff0000');
	expect($color->hex)->toBe('ff0000');
	expect($color->oklch)->toBeArray();
});

test('constructor accepts rgb() string', function (): void {
	$color = new ColorData('rgb(255, 0, 0)');
	expect($color->hex)->toBe('#ff0000');
	expect($color->oklch)->toBeArray();
});

test('constructor accepts hsl() string', function (): void {
	$color = new ColorData('hsl(0, 100%, 50%)');
	expect($color->hex)->toBeString();
	expect($color->oklch)->toBeArray();
});

test('constructor accepts oklch() string with commas (legacy syntax)', function (): void {
	$color = new ColorData('oklch(0.627, 0.258, 29.2)');
	expect($color->hex)->toBeString();
	expect($color->oklch)->toBeArray();
});

test('constructor accepts modern oklch() syntax with percent and spaces', function (): void {
	$color = new ColorData('oklch(70% 0.15 180)');
	expect($color->hex)->toBeString();
	expect($color->oklch['l'])->toEqual(70);
	expect($color->oklch['c'])->toEqual(0.15);
	expect($color->oklch['h'])->toEqual(180);
});

test('constructor with empty string defaults to black', function (): void {
	$color = new ColorData('');
	expect($color->hex)->toBe('#000000');
	expect($color->oklch)->toBeArray();
});

test('constructor with default argument defaults to black', function (): void {
	$color = new ColorData();
	expect($color->hex)->toBe('#000000');
});

test('constructor throws InvalidArgumentException for unparseable strings', function (): void {
	expect(fn (): ColorData => new ColorData('totally-not-a-color'))
		->toThrow(InvalidArgumentException::class, 'Invalid color format');
});

// ===== Constructor: array inputs =====

test('constructor accepts array with both hex and oklch', function (): void {
	$color = new ColorData([
		'hex'   => '#00ff00',
		'oklch' => ['l' => 86.644, 'c' => 0.295, 'h' => 142.495],
	]);
	expect($color->hex)->toBe('#00ff00');
	expect($color->oklch)->toBe(['l' => 86.644, 'c' => 0.295, 'h' => 142.495]);
});

test('constructor with array missing hex regenerates hex from oklch', function (): void {
	$color = new ColorData([
		'oklch' => ['l' => 86.644, 'c' => 0.295, 'h' => 142.495],
	]);
	expect($color->hex)->toBe('#00ff00');
});

test('constructor with array missing oklch regenerates oklch from hex', function (): void {
	$color = new ColorData(['hex' => '#ff0000']);
	expect($color->hex)->toBe('#ff0000');
	expect($color->oklch)->toBeArray()->toHaveKeys(['l', 'c', 'h']);
});

test('constructor stores settings array verbatim', function (): void {
	$settings = ['label' => 'Primary Color', 'required' => true];
	$color    = new ColorData('#0000ff', $settings);
	expect($color->settings)->toBe($settings);
	expect($color->hex)->toBe('#0000ff');
});

// ===== Static conversions: value accuracy =====

test('hexToRgb returns expected rgb coordinates for pure red', function (): void {
	expect(ColorData::hexToRgb('#ff0000'))->toEqual(['r' => 255, 'g' => 0, 'b' => 0]);
});

test('hexToRgb returns expected rgb coordinates for pure green', function (): void {
	expect(ColorData::hexToRgb('#00ff00'))->toEqual(['r' => 0, 'g' => 255, 'b' => 0]);
});

test('hexToRgb returns expected rgb coordinates for pure blue', function (): void {
	expect(ColorData::hexToRgb('#0000ff'))->toEqual(['r' => 0, 'g' => 0, 'b' => 255]);
});

test('hexToRgb handles arbitrary mid-range colors', function (): void {
	expect(ColorData::hexToRgb('#3366ff'))->toEqual(['r' => 51, 'g' => 102, 'b' => 255]);
});

test('hexToOklch returns array with float l/c/h keys', function (): void {
	$oklch = ColorData::hexToOklch('#ff0000');
	expect($oklch)->toHaveKeys(['l', 'c', 'h']);
	expect($oklch['l'])->toBeFloat();
	expect($oklch['c'])->toBeFloat();
	expect($oklch['h'])->toBeFloat();
});

test('hexToOklch returns expected reference values for sRGB red', function (): void {
	$oklch = ColorData::hexToOklch('#ff0000');
	// Published reference for sRGB red: oklch(62.8% 0.258 29.23)
	expect($oklch['l'])->toBeGreaterThan(62.0)->toBeLessThan(64.0);
	expect($oklch['c'])->toBeGreaterThan(0.24)->toBeLessThan(0.27);
	expect($oklch['h'])->toBeGreaterThan(28.0)->toBeLessThan(31.0);
});

test('hexToHsl returns array with float h/s/l keys', function (): void {
	$hsl = ColorData::hexToHsl('#ff0000');
	expect($hsl)->toHaveKeys(['h', 's', 'l']);
	expect($hsl['h'])->toBeFloat();
	expect($hsl['s'])->toBeFloat();
	expect($hsl['l'])->toBeFloat();
});

test('hexToHsl returns near-canonical hsl for pure red', function (): void {
	$hsl = ColorData::hexToHsl('#ff0000');
	// Pure red is hsl(0, 100%, 50%)
	expect($hsl['h'])->toBeLessThan(1.0);
	expect($hsl['s'])->toBeGreaterThan(99.0);
	expect($hsl['l'])->toBeGreaterThan(49.0)->toBeLessThan(51.0);
});

test('hexToHsl returns 120 degrees hue for pure green', function (): void {
	$hsl = ColorData::hexToHsl('#00ff00');
	expect($hsl['h'])->toEqual(120.0);
});

test('hexToHsl returns 240 degrees hue for pure blue', function (): void {
	$hsl = ColorData::hexToHsl('#0000ff');
	expect($hsl['h'])->toEqual(240.0);
});

// ===== oklchToHex =====

test('oklchToHex returns 6-digit lowercase hex', function (): void {
	$hex = ColorData::oklchToHex(['l' => 62.8, 'c' => 0.258, 'h' => 29.23]);
	expect($hex)->toMatch('/^#[a-f0-9]{6}$/');
});

test('oklchToHex round-trips red within tolerance', function (): void {
	$oklch = ColorData::hexToOklch('#ff0000');
	$hex   = ColorData::oklchToHex($oklch);
	$rgb   = ColorData::hexToRgb($hex);
	// Should be very close to (255, 0, 0)
	expect($rgb['r'])->toBeGreaterThan(250);
	expect($rgb['g'])->toBeLessThan(10);
	expect($rgb['b'])->toBeLessThan(10);
});

test('oklchToHex handles pure black', function (): void {
	$hex = ColorData::oklchToHex(['l' => 0, 'c' => 0, 'h' => 0]);
	expect($hex)->toBe('#000000');
});

// ===== oklchChange =====

test('oklchChange applies lightness modifier as absolute set', function (): void {
	$result = ColorData::oklchChange(
		['l' => 0.5, 'c' => 0.1, 'h' => 180.0],
		['l' => 0.2],
	);
	expect($result['l'])->toEqual(0.2);
	expect($result['c'])->toEqual(0.1); // unchanged
	expect($result['h'])->toEqual(180.0); // unchanged
});

test('oklchChange applies hue rotation formula', function (): void {
	$result = ColorData::oklchChange(
		['l' => 50, 'c' => 0.2, 'h' => 100],
		['h' => '+50'],
	);
	expect($result['h'])->toEqual(150);
});

test('oklchChange wraps hue around 360 degrees', function (): void {
	$result = ColorData::oklchChange(
		['l' => 50, 'c' => 0.2, 'h' => 350],
		['h' => '+30'],
	);
	expect($result['h'])->toEqual(20.0);
});

// ===== transform / __toString =====

test('transform returns array with hex and oklch keys', function (): void {
	$result = (new ColorData('#00ff00'))->transform();
	expect($result)->toHaveKeys(['hex', 'oklch']);
	expect($result['hex'])->toBe('#00ff00');
	expect($result['oklch'])->toBeArray();
});

test('__toString produces CSS oklch() syntax', function (): void {
	$str = (string)new ColorData('#ff0000');
	expect($str)->toStartWith('oklch(');
	expect($str)->toEndWith(')');
	expect($str)->toContain('%');
});

// ===== Round-trips and edge cases =====

test('hex string parses through all conversion outputs', function (): void {
	$oklch = ColorData::hexToOklch('#abcdef');
	$rgb   = ColorData::hexToRgb('#abc');
	$hsl   = ColorData::hexToHsl('#123456');

	expect($oklch)->toHaveKeys(['l', 'c', 'h']);
	expect($rgb)->toHaveKeys(['r', 'g', 'b']);
	expect($hsl)->toHaveKeys(['h', 's', 'l']);
});

test('rgb() string with various values produces valid hex', function (): void {
	$color = new ColorData('rgb(128, 64, 192)');
	expect($color->hex)->toMatch('/^#[a-f0-9]{6}$/i');
});

test('hsl() string with various values produces valid hex', function (): void {
	$color = new ColorData('hsl(240, 100%, 50%)');
	expect($color->hex)->toMatch('/^#[a-f0-9]{6}$/i');
});

test('oklch() string with various values produces valid hex', function (): void {
	$color = new ColorData('oklch(70, 0.15, 180)');
	expect($color->hex)->toMatch('/^#[a-f0-9]{6}$/i');
});

test('oklch() with percent sign in modern syntax parses correctly', function (): void {
	$color = new ColorData('oklch(54.5% 0.25 264)');
	expect($color->oklch['l'])->toEqual(54.5);
	expect($color->oklch['c'])->toEqual(0.25);
	expect($color->oklch['h'])->toEqual(264);
	expect($color->hex)->toMatch('/^#[a-f0-9]{6}$/i');
});
