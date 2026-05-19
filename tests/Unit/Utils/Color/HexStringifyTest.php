<?php

declare(strict_types=1);

use TotalCMS\Utils\Color\Converters\HexRgb;

/**
 * Adapted from upstream couleur's utils/hexRgb/StringifyTest.php
 * Original: https://github.com/matthieumastadenis/couleur/blob/main/src/tests/utils/hexRgb/StringifyTest.php
 *
 * Fuzz tests the hex stringify function across the `sharp`, `short`, `alpha`,
 * and `uppercase` parameters with randomly-generated hex digit pairs.
 */

const HEX_FUZZ_LOOPS = 30;
const HEX_CHARS      = ['0','1','2','3','4','5','6','7','8','9','A','B','C','D','E','F'];

/** Returns a random hex char, optionally excluding $not. */
function randomHexChar(?string $not = null): string
{
	do {
		$char = HEX_CHARS[array_rand(HEX_CHARS)];
	} while ($char === $not);
	return $char;
}

/**
 * Returns a random 2-char hex pair. If $same is true the pair is "XX" (so the
 * stringify short form can compress it), otherwise it's two different chars.
 */
function randomHexPair(bool $same = false, ?string $not = null): string
{
	do {
		$c1     = randomHexChar();
		$number = $same ? $c1 . $c1 : $c1 . randomHexChar($c1);
	} while ($number === $not);
	return $number;
}

// ===== sharp parameter =====

test('hex stringify starts with # by default', function (): void {
	for ($i = 0; $i < HEX_FUZZ_LOOPS; $i++) {
		expect(HexRgb::stringify(randomHexPair(), randomHexPair(), randomHexPair()))->toStartWith('#');
	}
});

test('hex stringify starts with # when sharp:true', function (): void {
	expect(HexRgb::stringify('A1', 'B2', 'C3', sharp: true))->toStartWith('#');
});

test('hex stringify omits # when sharp:false', function (): void {
	expect(HexRgb::stringify('A1', 'B2', 'C3', sharp: false))->not->toStartWith('#');
});

// ===== alpha / opacity =====

test('hex stringify omits opacity by default for varied input', function (): void {
	for ($i = 0; $i < HEX_FUZZ_LOOPS; $i++) {
		$same = (bool) ($i % 2);
		$hex  = HexRgb::stringify(randomHexPair($same), randomHexPair($same), randomHexPair($same));
		expect(strlen($hex))->toBe($same ? 4 : 7);
	}
});

test('hex stringify omits opacity when alpha is FF by default', function (): void {
	for ($i = 0; $i < HEX_FUZZ_LOOPS; $i++) {
		$same = (bool) ($i % 2);
		$o    = $same ? 'FF' : 'ff';
		$hex  = HexRgb::stringify(randomHexPair($same), randomHexPair($same), randomHexPair($same), $o);
		expect(strlen($hex))->toBe($same ? 4 : 7);
	}
});

test('hex stringify includes opacity when not FF by default', function (): void {
	for ($i = 0; $i < HEX_FUZZ_LOOPS; $i++) {
		$same = (bool) ($i % 2);
		$o    = randomHexPair($same, 'FF');
		$hex  = HexRgb::stringify(randomHexPair($same), randomHexPair($same), randomHexPair($same), $o);
		expect(strlen($hex))->toBe($same ? 5 : 9);
		expect($hex)->toEndWith($same ? $o[0] : $o);
	}
});

test('hex stringify omits opacity when alpha:false', function (): void {
	$hex = HexRgb::stringify('A1', 'B2', 'C3', 'AB', alpha: false);
	expect(strlen($hex))->toBe(7);
});

test('hex stringify always includes opacity when alpha:true even at FF', function (): void {
	$hex = HexRgb::stringify('A1', 'B2', 'C3', 'FF', alpha: true);
	expect(strlen($hex))->toBe(9);
});

// ===== short / long form =====

test('short hex chars produce identical output to expanded form by default', function (): void {
	for ($i = 0; $i < HEX_FUZZ_LOOPS; $i++) {
		$r = randomHexChar();
		$g = randomHexChar();
		$b = randomHexChar();
		expect(HexRgb::stringify($r, $g, $b))->toBe(HexRgb::stringify($r . $r, $g . $g, $b . $b));
	}
});

test('returns short form when all channels can be compressed (default)', function (): void {
	expect(strlen(HexRgb::stringify('AA', 'BB', 'CC')))->toBe(4);
});

test('returns long form when any channel cannot be compressed', function (): void {
	expect(strlen(HexRgb::stringify('A1', 'BB', 'CC')))->toBe(7);
});

test('forces short form when short:true and possible', function (): void {
	expect(strlen(HexRgb::stringify('AA', 'BB', 'CC', short: true)))->toBe(4);
});

test('forces long form when short:false', function (): void {
	expect(strlen(HexRgb::stringify('AA', 'BB', 'CC', short: false)))->toBe(7);
});

// ===== uppercase / lowercase =====

test('forces uppercase output when uppercase:true', function (): void {
	$hex = HexRgb::stringify('a1', 'b2', 'c3', uppercase: true);
	expect($hex)->toBe(strtoupper($hex));
});

test('forces lowercase output when uppercase:false', function (): void {
	$hex = HexRgb::stringify('A1', 'B2', 'C3', uppercase: false);
	expect($hex)->toBe(strtolower($hex));
});
