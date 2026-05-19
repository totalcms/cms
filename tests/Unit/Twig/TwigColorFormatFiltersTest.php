<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

/**
 * Tests for the Twig color *format conversion* filters:
 *   hexToColor, hex, rgb, hsl, color, colour, oklch
 *
 * These produce CSS-ready strings that customer templates use directly
 * (e.g. `style="background: {{ color | rgb }};"`). Alpha and wrap-vs-bare
 * variants are tested for every formatter.
 *
 * The four manipulation filters (lightness/chroma/hue/adjustColor) live in
 * ColorAdjustmentFiltersTest.php.
 */

// ===== hexToColor =====

test('hexToColor returns array with hex and oklch keys', function (): void {
	$result = TotalCMSTwigFilters::hexToColor('#ff0000');
	expect($result)->toBeArray();
	expect($result)->toHaveKeys(['hex', 'oklch']);
	expect($result['hex'])->toBe('#ff0000');
	expect($result['oklch'])->toBeArray()->toHaveKeys(['l', 'c', 'h']);
});

test('hexToColor preserves original hex string verbatim', function (): void {
	expect(TotalCMSTwigFilters::hexToColor('#3366ff')['hex'])->toBe('#3366ff');
	expect(TotalCMSTwigFilters::hexToColor('#abc')['hex'])->toBe('#abc');
});

// ===== hex =====

test('hex returns the hex string from a color array', function (): void {
	$color = ['hex' => '#3366ff', 'oklch' => ['l' => 50, 'c' => 0.2, 'h' => 250]];
	expect(TotalCMSTwigFilters::hex($color))->toBe('#3366ff');
});

test('hex returns empty string for null color', function (): void {
	expect(TotalCMSTwigFilters::hex(null))->toBe('');
});

test('hex falls back to #000000 when hex key is missing', function (): void {
	expect(TotalCMSTwigFilters::hex(['oklch' => ['l' => 0, 'c' => 0, 'h' => 0]]))->toBe('#000000');
});

// ===== rgb =====

test('rgb wraps in rgb() by default', function (): void {
	$color  = TotalCMSTwigFilters::hexToColor('#ff0000');
	$result = TotalCMSTwigFilters::rgb($color);
	expect($result)->toBe('rgb(255 0 0)');
});

test('rgb includes alpha channel when not 100', function (): void {
	$color  = TotalCMSTwigFilters::hexToColor('#ff0000');
	$result = TotalCMSTwigFilters::rgb($color, 50);
	expect($result)->toBe('rgb(255 0 0 / 0.50)');
});

test('rgb omits wrapping when wrap is false', function (): void {
	$color  = TotalCMSTwigFilters::hexToColor('#ff0000');
	$result = TotalCMSTwigFilters::rgb($color, 100, false);
	expect($result)->toBe('255 0 0');
});

test('rgb returns empty string for null color', function (): void {
	expect(TotalCMSTwigFilters::rgb(null))->toBe('');
});

test('rgb formats green and blue channels correctly', function (): void {
	expect(TotalCMSTwigFilters::rgb(TotalCMSTwigFilters::hexToColor('#00ff00')))->toBe('rgb(0 255 0)');
	expect(TotalCMSTwigFilters::rgb(TotalCMSTwigFilters::hexToColor('#0000ff')))->toBe('rgb(0 0 255)');
});

// ===== hsl =====

test('hsl wraps in hsl() by default', function (): void {
	$color  = TotalCMSTwigFilters::hexToColor('#ff0000');
	$result = TotalCMSTwigFilters::hsl($color);
	expect($result)->toMatch('/^hsl\(\d+ \d+% \d+%\)$/');
});

test('hsl includes alpha channel when not 100', function (): void {
	$color  = TotalCMSTwigFilters::hexToColor('#ff0000');
	$result = TotalCMSTwigFilters::hsl($color, 75);
	expect($result)->toMatch('/^hsl\(\d+ \d+% \d+% \/ 0\.75\)$/');
});

test('hsl omits wrapping when wrap is false', function (): void {
	$color  = TotalCMSTwigFilters::hexToColor('#ff0000');
	$result = TotalCMSTwigFilters::hsl($color, 100, false);
	expect($result)->toMatch('/^\d+ \d+% \d+%$/');
});

test('hsl returns empty string for null color', function (): void {
	expect(TotalCMSTwigFilters::hsl(null))->toBe('');
});

// ===== oklch (and aliases color, colour) =====

test('oklch wraps in oklch() by default', function (): void {
	$color  = TotalCMSTwigFilters::hexToColor('#ff0000');
	$result = TotalCMSTwigFilters::oklch($color);
	expect($result)->toStartWith('oklch(');
	expect($result)->toEndWith(')');
	expect($result)->toContain('%');
});

test('oklch includes alpha channel when not 100', function (): void {
	$color  = TotalCMSTwigFilters::hexToColor('#ff0000');
	$result = TotalCMSTwigFilters::oklch($color, 80);
	expect($result)->toEndWith(' / 0.80)');
});

test('oklch omits wrapping when wrap is false', function (): void {
	$color  = TotalCMSTwigFilters::hexToColor('#ff0000');
	$result = TotalCMSTwigFilters::oklch($color, 100, false);
	expect($result)->not->toStartWith('oklch(');
	expect($result)->toContain('%');
});

test('oklch returns empty string for null color', function (): void {
	expect(TotalCMSTwigFilters::oklch(null))->toBe('');
});

test('oklch falls back to zero values when oklch key is missing', function (): void {
	$result = TotalCMSTwigFilters::oklch(['hex' => '#000000']);
	expect($result)->toBe('oklch(0.000% 0.000 0.000)');
});

test('color is an alias for oklch', function (): void {
	$color = TotalCMSTwigFilters::hexToColor('#ff0000');
	expect(TotalCMSTwigFilters::color($color))->toBe(TotalCMSTwigFilters::oklch($color));
	expect(TotalCMSTwigFilters::color($color, 50))->toBe(TotalCMSTwigFilters::oklch($color, 50));
	expect(TotalCMSTwigFilters::color(null))->toBe('');
});

test('colour is an alias for oklch (British spelling)', function (): void {
	$color = TotalCMSTwigFilters::hexToColor('#ff0000');
	expect(TotalCMSTwigFilters::colour($color))->toBe(TotalCMSTwigFilters::oklch($color));
	expect(TotalCMSTwigFilters::colour($color, 75, false))->toBe(TotalCMSTwigFilters::oklch($color, 75, false));
	expect(TotalCMSTwigFilters::colour(null))->toBe('');
});

// ===== Integration: filters compose cleanly =====

test('hexToColor output is consumable by every format filter', function (): void {
	$color = TotalCMSTwigFilters::hexToColor('#3366ff');
	expect(TotalCMSTwigFilters::hex($color))->toBe('#3366ff');
	expect(TotalCMSTwigFilters::rgb($color))->toStartWith('rgb(');
	expect(TotalCMSTwigFilters::hsl($color))->toStartWith('hsl(');
	expect(TotalCMSTwigFilters::oklch($color))->toStartWith('oklch(');
	expect(TotalCMSTwigFilters::color($color))->toStartWith('oklch(');
});
