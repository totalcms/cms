<?php

declare(strict_types=1);

use TotalCMS\Utils\Color\Couleur\CssColor;

/**
 * Adapted from upstream couleur's CssColorTest.php
 * Original: https://github.com/matthieumastadenis/couleur/blob/main/src/tests/CssColorTest.php
 *
 * Tests the CSS named colors enum (148 standard color names mapped to RGB/hex).
 */

/** @var array<string, array{case: CssColor, hexRgb: array{string,string,string}, rgb: array{int,int,int}}> */
const CSS_COLOR_SAMPLES = [
	'lightpink' => [
		'case'   => CssColor::lightpink,
		'hexRgb' => ['FF', 'B6', 'C1'],
		'rgb'    => [255, 182, 193],
	],
	'lightsteelblue' => [
		'case'   => CssColor::lightsteelblue,
		'hexRgb' => ['B0', 'C4', 'DE'],
		'rgb'    => [176, 196, 222],
	],
	'mediumorchid' => [
		'case'   => CssColor::mediumorchid,
		'hexRgb' => ['BA', '55', 'D3'],
		'rgb'    => [186, 85, 211],
	],
	'papayawhip' => [
		'case'   => CssColor::papayawhip,
		'hexRgb' => ['FF', 'EF', 'D5'],
		'rgb'    => [255, 239, 213],
	],
	'red' => [
		'case'   => CssColor::red,
		'hexRgb' => ['FF', '00', '00'],
		'rgb'    => [255, 0, 0],
	],
	'slategray' => [
		'case'   => CssColor::slategray,
		'hexRgb' => ['70', '80', '90'],
		'rgb'    => [112, 128, 144],
	],
];

test('CssColor enum has 148 cases', function (): void {
	expect(CssColor::cases())->toHaveCount(148);
});

test('CssColor::names returns 148 existing names', function (): void {
	$names = CssColor::names();
	expect($names)->toHaveCount(148);
	foreach ($names as $name) {
		expect(CssColor::exists($name))->toBeTrue("'{$name}' should exist");
	}
});

test('CssColor::exists returns true for known color names', function (): void {
	foreach (array_keys(CSS_COLOR_SAMPLES) as $name) {
		expect(CssColor::exists($name))->toBeTrue();
	}
});

test('CssColor::fromCss resolves names to expected enum cases', function (): void {
	foreach (CSS_COLOR_SAMPLES as $name => $data) {
		expect(CssColor::fromCss($name))->toBe($data['case']);
	}
});

test('CssColor::fromHexRgb resolves hex coords to expected enum cases', function (): void {
	foreach (CSS_COLOR_SAMPLES as $name => $data) {
		expect(CssColor::fromHexRgb(...$data['hexRgb']))->toBe($data['case']);
	}
});

test('CssColor::fromRgb resolves rgb coords to expected enum cases', function (): void {
	foreach (CSS_COLOR_SAMPLES as $name => $data) {
		expect(CssColor::fromRgb(...$data['rgb']))->toBe($data['case']);
	}
});

test('CssColor::allHexRgbCoordinates contains all sample colors', function (): void {
	$coordinates = CssColor::allHexRgbCoordinates();
	foreach (CSS_COLOR_SAMPLES as $name => $data) {
		expect($coordinates)->toHaveKey($name);
		expect($coordinates[$name])->toBe($data['hexRgb']);
	}
});

test('CssColor::allRgbCoordinates contains all sample colors', function (): void {
	$coordinates = CssColor::allRgbCoordinates();
	foreach (CSS_COLOR_SAMPLES as $name => $data) {
		expect($coordinates)->toHaveKey($name);
		expect($coordinates[$name])->toBe($data['rgb']);
	}
});

test('toHexRgbCoordinates returns expected hex coords for each case', function (): void {
	foreach (CSS_COLOR_SAMPLES as $data) {
		expect($data['case']->toHexRgbCoordinates())->toBe($data['hexRgb']);
	}
});

test('toRgbCoordinates returns expected rgb coords for each case', function (): void {
	foreach (CSS_COLOR_SAMPLES as $data) {
		expect($data['case']->toRgbCoordinates())->toBe($data['rgb']);
	}
});
