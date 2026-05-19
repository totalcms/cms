<?php

declare(strict_types=1);

use TotalCMS\Utils\Color\Couleur\ColorFactory;
use TotalCMS\Utils\Color\Couleur\ColorSpace;
use TotalCMS\Utils\Color\Couleur\exceptions\UnknownColorSpace;

use function TotalCMS\Utils\Color\Couleur\utils\hsl\toHsv as hslToHsv;
use function TotalCMS\Utils\Color\Couleur\utils\hsl\toRgb as hslToRgb;
use function TotalCMS\Utils\Color\Couleur\utils\hsv\toHsl as hsvToHsl;
use function TotalCMS\Utils\Color\Couleur\utils\hwb\toHsv as hwbToHsv;
use function TotalCMS\Utils\Color\Couleur\utils\rgb\toHsl as rgbToHsl;
use function TotalCMS\Utils\Color\Couleur\utils\linP3\stringify as linP3Stringify;
use function TotalCMS\Utils\Color\Couleur\utils\linProPhoto\stringify as linProPhotoStringify;
use function TotalCMS\Utils\Color\Couleur\utils\linRgb\stringify as linRgbStringify;
use function TotalCMS\Utils\Color\Couleur\utils\p3\stringify as p3Stringify;
use function TotalCMS\Utils\Color\Couleur\utils\proPhoto\stringify as proPhotoStringify;
use function TotalCMS\Utils\Color\Couleur\utils\xyzD50\stringify as xyzD50Stringify;
use function TotalCMS\Utils\Color\Couleur\utils\xyzD65\stringify as xyzD65Stringify;

// ===== Bug: $hue undefined when switch finds no match (rgb/conversions.php) =====
// Defensive default initialization protects against floating-point precision misses.

test('rgb to hsl converts pure gray without undefined variable warning', function (): void {
	$result = rgbToHsl(128, 128, 128);
	expect($result)->toBeArray();
	expect($result[0])->toBe(0.0);    // hue defaults to 0 for grays
	expect($result[1])->toBe(0.0);    // saturation = 0 (gray)
});

test('rgb to hsl converts pure black without crashing', function (): void {
	$result = rgbToHsl(0, 0, 0);
	expect($result[0])->toBe(0.0);
	expect($result[1])->toBe(0.0);
	expect($result[2])->toBe(0.0);
});

test('rgb to hsl converts pure white without crashing', function (): void {
	$result = rgbToHsl(255, 255, 255);
	expect($result[0])->toBe(0.0);
	expect($result[1])->toBe(0.0);
	expect($result[2])->toBe(100.0);
});

// ===== Bug: ($value === 0) strict comparison against float (hsl/hsv/hwb conversions) =====
// Float 0.0 was never matched by int 0 under strict comparison, causing divide-by-zero.

test('hsl to hsv on pure black does not divide by zero', function (): void {
	$result = hslToHsv(0, 0, 0);
	expect((float) $result[1])->toEqual(0.0);
	expect((float) $result[2])->toEqual(0.0);
	expect(is_finite((float) $result[1]))->toBeTrue();
	expect(is_finite((float) $result[2]))->toBeTrue();
});

test('hsv to hsl on pure black does not divide by zero', function (): void {
	$result = hsvToHsl(0, 0, 0);
	expect((float) $result[1])->toEqual(0.0);
	expect((float) $result[2])->toEqual(0.0);
	expect(is_finite((float) $result[1]))->toBeTrue();
});

test('hsv to hsl on pure white does not divide by zero', function (): void {
	$result = hsvToHsl(0, 0, 100);
	expect((float) $result[2])->toEqual(100.0);
	expect(is_finite((float) $result[1]))->toBeTrue();
});

test('hwb to hsv on full whiteness does not divide by zero', function (): void {
	// whiteness + blackness >= 1 takes a different branch; this triggers the $value === 0 path
	$result = hwbToHsv(0, 0, 100);
	expect($result[1])->toBeNumeric();
	expect(is_finite((float) $result[1]))->toBeTrue();
});

// ===== Bug: missing $precision parameter (hsl/conversions.php toRgb) =====

test('hsl to rgb accepts explicit precision parameter', function (): void {
	// Was using undefined $precision variable; now exposed as parameter
	$result = hslToRgb(120, 100, 50, 100, precision: 2);
	expect($result)->toBeArray();
	expect($result[0])->toBeFloat();
});

test('hsl to rgb works with default precision (regression: undefined variable)', function (): void {
	$result = hslToRgb(120, 100, 50);
	expect($result)->toBeArray();
	expect(count($result))->toBe(4);
});

// ===== Bug: dead $legacy ??= against undeclared variable (7 stringify functions) =====
// These functions referenced an undeclared $legacy var, emitting "undefined variable" warnings.

test('linP3 stringify produces valid color() string without warning', function (): void {
	$result = linP3Stringify(0.5, 0.5, 0.5);
	expect($result)->toStartWith('color(p3-linear ');
});

test('linProPhoto stringify produces valid color() string', function (): void {
	$result = linProPhotoStringify(0.5, 0.5, 0.5);
	expect($result)->toStartWith('color(');
});

test('linRgb stringify produces valid color() string', function (): void {
	$result = linRgbStringify(0.5, 0.5, 0.5);
	expect($result)->toStartWith('color(');
});

test('p3 stringify produces valid color() string', function (): void {
	$result = p3Stringify(0.5, 0.5, 0.5);
	expect($result)->toStartWith('color(');
});

test('proPhoto stringify produces valid color() string', function (): void {
	$result = proPhotoStringify(0.5, 0.5, 0.5);
	expect($result)->toStartWith('color(');
});

test('xyzD50 stringify produces valid color() string', function (): void {
	$result = xyzD50Stringify(0.5, 0.5, 0.5);
	expect($result)->toStartWith('color(');
});

test('xyzD65 stringify produces valid color() string', function (): void {
	$result = xyzD65Stringify(0.5, 0.5, 0.5);
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
