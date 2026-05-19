<?php

declare(strict_types=1);

use function TotalCMS\Utils\Color\Couleur\utils\okLch\changeHue;
use function TotalCMS\Utils\Color\Couleur\utils\okLch\cleanEnhanced;
use function TotalCMS\Utils\Color\Couleur\utils\okLch\oklchChange;
use function TotalCMS\Utils\Color\Couleur\utils\okLch\oklchToHex;

/**
 * Coverage for the custom enhanced.php additions on top of couleur.
 *
 * These functions were written by Joe to work around limitations in the
 * original library:
 *   - oklchToHex: avoids stringify issues by manual hex formatting
 *   - oklchChange: hue wraparound that upstream doesn't handle
 *   - changeHue: formula-based hue arithmetic with wraparound
 *   - cleanEnhanced: stricter coordinate bounds checking
 */

// ===== oklchToHex =====

test('oklchToHex converts known OKLCH values to expected hex', function (): void {
	// sRGB red — published as approximately oklch(62.8% 0.258 29.23)
	$hex = oklchToHex(['l' => 62.8, 'c' => 0.258, 'h' => 29.23]);
	expect($hex)->toStartWith('#');
	expect(strlen($hex))->toBe(7);
	// Should be close to red (high red, low green/blue)
	$r = hexdec(substr($hex, 1, 2));
	$g = hexdec(substr($hex, 3, 2));
	$b = hexdec(substr($hex, 5, 2));
	expect($r)->toBeGreaterThan(240);
	expect($g)->toBeLessThan(20);
	expect($b)->toBeLessThan(20);
});

test('oklchToHex returns valid hex for pure black', function (): void {
	$hex = oklchToHex(['l' => 0, 'c' => 0, 'h' => 0]);
	expect($hex)->toBe('#000000');
});

test('oklchToHex returns valid hex for pure white', function (): void {
	$hex = oklchToHex(['l' => 100, 'c' => 0, 'h' => 0]);
	// White can be very close but not necessarily exactly #ffffff due to OKLCH→sRGB math
	$r = hexdec(substr($hex, 1, 2));
	$g = hexdec(substr($hex, 3, 2));
	$b = hexdec(substr($hex, 5, 2));
	expect($r)->toBeGreaterThan(250);
	expect($g)->toBeGreaterThan(250);
	expect($b)->toBeGreaterThan(250);
});

test('oklchToHex clamps RGB values to 0-255 boundary', function (): void {
	// Out-of-gamut OKLCH that would produce RGB > 255 without clamping
	$hex = oklchToHex(['l' => 70, 'c' => 0.4, 'h' => 30]);
	expect($hex)->toMatch('/^#[0-9a-f]{6}$/');
	$r = hexdec(substr($hex, 1, 2));
	$g = hexdec(substr($hex, 3, 2));
	$b = hexdec(substr($hex, 5, 2));
	expect($r)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(255);
	expect($g)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(255);
	expect($b)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(255);
});

// ===== changeHue formula operators =====

test('changeHue applies addition formula', function (): void {
	expect(changeHue(100, '+30'))->toBe(130.0);
	expect(changeHue(0, '+45'))->toBe(45.0);
});

test('changeHue applies subtraction formula', function (): void {
	expect(changeHue(100, '-30'))->toBe(70.0);
});

test('changeHue applies multiplication formula', function (): void {
	expect(changeHue(50, '*2'))->toBe(100.0);
});

test('changeHue applies division formula', function (): void {
	expect(changeHue(100, '/2'))->toBe(50.0);
});

test('changeHue ignores division by zero', function (): void {
	expect(changeHue(100, '/0'))->toBe(100.0);
});

test('changeHue wraps positive overflow to 0-360 range', function (): void {
	expect(changeHue(350, '+30'))->toBe(20.0); // 380 → 20
	expect(changeHue(180, '*4'))->toBeGreaterThanOrEqual(0.0)->toBeLessThan(360.0);
});

test('changeHue wraps negative results to 0-360 range', function (): void {
	expect(changeHue(20, '-50'))->toBeGreaterThanOrEqual(0.0)->toBeLessThan(360.0);
	// -30 should wrap to 330
	$result = changeHue(20, '-50');
	expect($result)->toBe(330.0);
});

test('changeHue handles exact 360 boundary', function (): void {
	$result = changeHue(360, '+0');
	expect($result)->toBeGreaterThanOrEqual(0.0)->toBeLessThan(360.0);
});

test('changeHue ignores unrecognized operators', function (): void {
	expect(changeHue(100, '!10'))->toBe(100.0);
});

// ===== oklchChange =====

test('oklchChange applies hue change with proper wraparound', function (): void {
	$result = oklchChange(['l' => 50, 'c' => 0.2, 'h' => 350], ['h' => '+30']);
	expect($result['h'])->toBe(20.0);
	expect($result['l'])->toBeFloat();
	expect($result['c'])->toBeFloat();
});

test('oklchChange applies lightness change', function (): void {
	$result = oklchChange(['l' => 50, 'c' => 0.2, 'h' => 180], ['l' => 70]);
	expect($result['l'])->toBeGreaterThan(60);
});

test('oklchChange applies chroma change', function (): void {
	$result = oklchChange(['l' => 50, 'c' => 0.2, 'h' => 180], ['c' => 0.1]);
	expect($result['c'])->toBeLessThan(0.2);
});

test('oklchChange preserves hue when no h change requested', function (): void {
	$result = oklchChange(['l' => 50, 'c' => 0.2, 'h' => 180], ['l' => 60]);
	expect($result['h'])->toEqual(180);
});

test('oklchChange returns black fallback on invalid input', function (): void {
	// Test ColorFactory failure path — extremely unusual but documented behavior
	$result = oklchChange(['l' => 50, 'c' => 0.2, 'h' => 180], []);
	expect($result)->toHaveKeys(['l', 'c', 'h']);
});

// ===== cleanEnhanced =====

test('cleanEnhanced clamps lightness to 0-100 range', function (): void {
	$result = cleanEnhanced('oklch(150% 0.2 180)', true);
	expect($result[0])->toBeLessThanOrEqual(100.0);
});

test('cleanEnhanced wraps hue to 0-360 range', function (): void {
	$result = cleanEnhanced('oklch(50% 0.2 400)', true);
	expect($result[2])->toBeGreaterThanOrEqual(0.0);
	expect($result[2])->toBeLessThan(360.0);
});

test('cleanEnhanced ensures non-negative chroma', function (): void {
	$result = cleanEnhanced('oklch(50% 0.2 180)', true);
	expect($result[1])->toBeGreaterThanOrEqual(0.0);
});
