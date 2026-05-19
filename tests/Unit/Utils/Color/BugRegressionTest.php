<?php

declare(strict_types=1);

use TotalCMS\Utils\Color\ColorFactory;
use TotalCMS\Utils\Color\ColorSpace;
use TotalCMS\Utils\Color\Converters\Hsl;
use TotalCMS\Utils\Color\Converters\LinRgb;
use TotalCMS\Utils\Color\Converters\Rgb;
use TotalCMS\Utils\Color\Converters\XyzD65;
use TotalCMS\Utils\Color\Exceptions\UnknownColorSpace;

// ===== Bug: $hue undefined when switch finds no match (rgb/conversions.php) =====
// Defensive default initialization protects against floating-point precision misses.

test('rgb to hsl converts pure gray without undefined variable warning', function (): void {
	$result = Rgb::toHsl(128, 128, 128);
	expect($result)->toBeArray();
	expect($result[0])->toBe(0.0);    // hue defaults to 0 for grays
	expect($result[1])->toBe(0.0);    // saturation = 0 (gray)
});

test('rgb to hsl converts pure black without crashing', function (): void {
	$result = Rgb::toHsl(0, 0, 0);
	expect($result[0])->toBe(0.0);
	expect($result[1])->toBe(0.0);
	expect($result[2])->toBe(0.0);
});

test('rgb to hsl converts pure white without crashing', function (): void {
	$result = Rgb::toHsl(255, 255, 255);
	expect($result[0])->toBe(0.0);
	expect($result[1])->toBe(0.0);
	expect($result[2])->toBe(100.0);
});

// ===== Bug: ($value === 0) strict comparison against float (hsl/hsv/hwb conversions) =====
// Float 0.0 was never matched by int 0 under strict comparison, causing divide-by-zero.

// ===== Bug: missing $precision parameter (hsl/conversions.php toRgb) =====

test('hsl to rgb accepts explicit precision parameter', function (): void {
	// Was using undefined $precision variable; now exposed as parameter
	$result = Hsl::toRgb(120, 100, 50, 100, precision: 2);
	expect($result)->toBeArray();
	expect($result[0])->toBeFloat();
});

test('hsl to rgb works with default precision (regression: undefined variable)', function (): void {
	$result = Hsl::toRgb(120, 100, 50);
	expect($result)->toBeArray();
	expect(count($result))->toBe(4);
});

// ===== Bug: dead $legacy ??= against undeclared variable (intermediate-space stringify functions) =====
// These functions referenced an undeclared $legacy var, emitting "undefined variable" warnings.
// Only linRgb and xyzD65 remain (intermediates in the OKLCH conversion chain).

test('linRgb stringify produces valid color() string', function (): void {
	$result = LinRgb::stringify(0.5, 0.5, 0.5);
	expect($result)->toStartWith('color(');
});

test('xyzD65 stringify produces valid color() string', function (): void {
	$result = XyzD65::stringify(0.5, 0.5, 0.5);
	expect($result)->toStartWith('color(');
});

// ===== Bug: extra args silently dropped on ColorSpace::aliases() / allAliases() =====
// Callers passed args that the methods didn't accept. Fixed by removing the extra args.

test('ColorSpace::fromAlias resolves standard color space names', function (): void {
	expect(ColorSpace::fromAlias('rgb'))->toBe(ColorSpace::Rgb);
	expect(ColorSpace::fromAlias('hsl'))->toBe(ColorSpace::Hsl);
	expect(ColorSpace::fromAlias('oklch'))->toBe(ColorSpace::OkLch);
	expect(ColorSpace::fromAlias('hex'))->toBe(ColorSpace::HexRgb);
});

test('ColorSpace::allAliases returns a populated map', function (): void {
	$aliases = ColorSpace::allAliases();
	expect($aliases)->toBeArray();
	expect(count($aliases))->toBeGreaterThan(0);
	expect($aliases)->toHaveKey('rgb');
});

// ===== Bug: UnknownColorSpace exception silently swallowed its $space parameter =====

test('UnknownColorSpace exception includes the unknown space name in message', function (): void {
	$exception = new UnknownColorSpace('invalidspace');
	expect($exception->getMessage())->toContain('invalidspace');
});

test('UnknownColorSpace exception falls back to generic message when no space provided', function (): void {
	$exception = new UnknownColorSpace();
	expect($exception->getMessage())->toBe('Unknown color space');
});

// ===== Regression: ColorFactory full pipeline through fixed conversion functions =====

test('ColorFactory hex to oklch round-trip works (exercises fixed conversion graph)', function (): void {
	$oklch = ColorFactory::newOkLch('#3366ff', ColorSpace::HexRgb);
	expect($oklch)->not->toBeNull();
	$hex = ColorFactory::newHexRgb([$oklch->coordinates()[0], $oklch->coordinates()[1], $oklch->coordinates()[2]], ColorSpace::OkLch);
	expect($hex)->not->toBeNull();
});
