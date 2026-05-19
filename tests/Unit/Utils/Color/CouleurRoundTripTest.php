<?php

declare(strict_types=1);

use TotalCMS\Utils\Color\Couleur\colors\HexRgb;
use TotalCMS\Utils\Color\Couleur\colors\Hsl;
use TotalCMS\Utils\Color\Couleur\colors\OkLch;
use TotalCMS\Utils\Color\Couleur\colors\Rgb;
use TotalCMS\Utils\Color\Couleur\ColorFactory;
use TotalCMS\Utils\Color\Couleur\ColorSpace;

/**
 * Round-trip identity tests for the color conversion graph.
 *
 * Conversions are lossy in two ways:
 *   1. Hex quantizes RGB to 256 levels per channel
 *   2. Floating-point representation introduces drift
 *
 * Tests use tolerance-based comparison where appropriate.
 */

// ===== Reference colors used across round-trip tests =====

const REFERENCE_COLORS = [
	'red'        => '#ff0000',
	'green'      => '#00ff00',
	'blue'       => '#0000ff',
	'white'      => '#ffffff',
	'black'      => '#000000',
	'cyan'       => '#00ffff',
	'magenta'    => '#ff00ff',
	'yellow'     => '#ffff00',
	'gray'       => '#808080',
	'salmon'     => '#fa8072',
	'midnight'   => '#191970',
	'mediumblue' => '#0000cd',
];

// ===== hex → rgb → hex (lossless via integer rgb coordinates) =====

test('hex to rgb to hex round-trip preserves rgb coordinates exactly', function (): void {
	foreach (REFERENCE_COLORS as $name => $hex) {
		$rgb = ColorFactory::newRgb($hex, ColorSpace::HexRgb);
		expect($rgb)->toBeInstanceOf(Rgb::class, "{$name}: rgb conversion returned null");

		$rgbCoords = $rgb->coordinates();
		$backToHex = ColorFactory::newHexRgb([$rgbCoords[0], $rgbCoords[1], $rgbCoords[2]], ColorSpace::Rgb);
		expect($backToHex)->toBeInstanceOf(HexRgb::class, "{$name}: hex round-trip returned null");

		// Verify by converting both hexes back to rgb and comparing coordinates (avoids #ff0000 vs #F00 shorthand issue)
		$rgbFromOriginal = ColorFactory::newRgb($hex, ColorSpace::HexRgb)->coordinates();
		$rgbFromRoundTrip = ColorFactory::newRgb((string) $backToHex, ColorSpace::HexRgb)->coordinates();
		foreach (range(0, 2) as $channel) {
			expect((int) $rgbFromRoundTrip[$channel])
				->toBe((int) $rgbFromOriginal[$channel], "{$name}: channel {$channel} hex round-trip lost data");
		}
	}
});

// ===== rgb → hsl → rgb (within sRGB rounding tolerance) =====

test('rgb to hsl to rgb round-trip preserves color within tolerance', function (): void {
	foreach (REFERENCE_COLORS as $name => $hex) {
		$rgb = ColorFactory::newRgb($hex, ColorSpace::HexRgb);
		expect($rgb)->toBeInstanceOf(Rgb::class);

		$rgbCoords = $rgb->coordinates();
		$hsl       = ColorFactory::newHsl([$rgbCoords[0], $rgbCoords[1], $rgbCoords[2]], ColorSpace::Rgb);
		expect($hsl)->toBeInstanceOf(Hsl::class, "{$name}: hsl conversion returned null");

		$hslCoords = $hsl->coordinates();
		$backToRgb = ColorFactory::newRgb([$hslCoords[0], $hslCoords[1], $hslCoords[2]], ColorSpace::Hsl);
		expect($backToRgb)->toBeInstanceOf(Rgb::class);

		$resultCoords = $backToRgb->coordinates();
		foreach (range(0, 2) as $channel) {
			expect(abs((float) $resultCoords[$channel] - (float) $rgbCoords[$channel]))
				->toBeLessThan(1.5, "{$name}: channel {$channel} drifted past tolerance");
		}
	}
});

// ===== hex → oklch → hex (within sRGB rounding tolerance) =====

test('hex to oklch to hex round-trip preserves color within tolerance', function (): void {
	foreach (REFERENCE_COLORS as $name => $hex) {
		$oklch = ColorFactory::newOkLch($hex, ColorSpace::HexRgb);
		expect($oklch)->toBeInstanceOf(OkLch::class, "{$name}: oklch conversion returned null");

		$oklchCoords = $oklch->coordinates();
		$rgb         = ColorFactory::newRgb(
			[$oklchCoords[0], $oklchCoords[1], $oklchCoords[2]],
			ColorSpace::OkLch,
		);
		expect($rgb)->toBeInstanceOf(Rgb::class, "{$name}: rgb back-conversion returned null");

		$rgbCoords      = $rgb->coordinates();
		$originalRgb    = ColorFactory::newRgb($hex, ColorSpace::HexRgb);
		$originalCoords = $originalRgb->coordinates();

		foreach (range(0, 2) as $channel) {
			expect(abs((float) $rgbCoords[$channel] - (float) $originalCoords[$channel]))
				->toBeLessThan(2.0, "{$name}: channel {$channel} drifted past oklch round-trip tolerance");
		}
	}
});

// ===== Known reference values (sanity check vs published color science) =====

test('pure red converts to expected OKLCH values', function (): void {
	$oklch = ColorFactory::newOkLch('#ff0000', ColorSpace::HexRgb);
	expect($oklch)->toBeInstanceOf(OkLch::class);

	$coords = $oklch->coordinates();
	// Published reference: oklch(62.8% 0.258 29.23) for sRGB red
	expect((float) $coords[0])->toBeGreaterThan(62.0)->toBeLessThan(64.0);
	expect((float) $coords[1])->toBeGreaterThan(0.24)->toBeLessThan(0.27);
	expect((float) $coords[2])->toBeGreaterThan(28.0)->toBeLessThan(31.0);
});

test('pure white has OKLCH lightness near 100%', function (): void {
	$oklch  = ColorFactory::newOkLch('#ffffff', ColorSpace::HexRgb);
	$coords = $oklch->coordinates();
	expect((float) $coords[0])->toBeGreaterThan(99.0);
	expect((float) $coords[1])->toBeLessThan(0.01); // no chroma
});

test('pure black has OKLCH lightness near 0%', function (): void {
	$oklch  = ColorFactory::newOkLch('#000000', ColorSpace::HexRgb);
	$coords = $oklch->coordinates();
	expect((float) $coords[0])->toBeLessThan(1.0);
	expect((float) $coords[1])->toBeLessThan(0.01);
});

test('pure gray has near-zero OKLCH chroma', function (): void {
	$oklch  = ColorFactory::newOkLch('#808080', ColorSpace::HexRgb);
	$coords = $oklch->coordinates();
	expect((float) $coords[1])->toBeLessThan(0.01);
});
