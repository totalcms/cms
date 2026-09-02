<?php

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigPatterns;

/**
 * The `patterns.*` registry backs the HTML `pattern` attribute in form
 * builder fields (`cms.form.text('v', {}, {pattern: patterns.version})`), so
 * every entry is stored UNANCHORED — the browser anchors a `pattern`
 * attribute implicitly.
 *
 * JSON Schema does not: its `pattern` keyword is a substring match, so the
 * same string pasted into a schema's Extra Schema Definitions needs `^...$`
 * wrapped around it. Both shapes are asserted here because the docs tell
 * operators to use both.
 */
describe('TotalCMSTwigPatterns version patterns', function (): void {
	$anchored = fn (string $pattern, string $subject): bool
		=> preg_match('/^' . $pattern . '$/', $subject) === 1;

	// -------------------------
	// version — plain three-part
	// -------------------------

	test('version accepts a three-part release number', function () use ($anchored): void {
		$patterns = new TotalCMSTwigPatterns();

		expect($anchored($patterns->version, '3.5.0'))->toBeTrue();
		expect($anchored($patterns->version, '10.24.316'))->toBeTrue();
		expect($anchored($patterns->version, '0.0.1'))->toBeTrue();
	});

	test('version rejects anything that is not exactly three parts', function () use ($anchored): void {
		$patterns = new TotalCMSTwigPatterns();

		expect($anchored($patterns->version, '3.5'))->toBeFalse();
		expect($anchored($patterns->version, '3.5.0.1'))->toBeFalse();
		expect($anchored($patterns->version, 'v3.5.0'))->toBeFalse();
		expect($anchored($patterns->version, '3.5.0-rc.1'))->toBeFalse();
		expect($anchored($patterns->version, 'banana'))->toBeFalse();
	});

	// -------------------------
	// versionExtended — semver
	// -------------------------

	test('versionExtended accepts a plain release, so it is a superset of version', function () use ($anchored): void {
		$patterns = new TotalCMSTwigPatterns();

		expect($anchored($patterns->versionExtended, '3.5.0'))->toBeTrue();
	});

	test('versionExtended accepts an optional v prefix', function () use ($anchored): void {
		$patterns = new TotalCMSTwigPatterns();

		expect($anchored($patterns->versionExtended, 'v3.5.0'))->toBeTrue();
	});

	test('versionExtended accepts prerelease and build metadata', function () use ($anchored): void {
		$patterns = new TotalCMSTwigPatterns();

		expect($anchored($patterns->versionExtended, '3.5.1-rc.1'))->toBeTrue();
		expect($anchored($patterns->versionExtended, '3.5.0-alpha'))->toBeTrue();
		expect($anchored($patterns->versionExtended, '3.5.0+build.7'))->toBeTrue();
		expect($anchored($patterns->versionExtended, '1.0.0-beta.2+exp.sha.5114f85'))->toBeTrue();
	});

	test('versionExtended rejects leading zeros, per semver', function () use ($anchored): void {
		$patterns = new TotalCMSTwigPatterns();

		expect($anchored($patterns->versionExtended, '01.2.3'))->toBeFalse();
		expect($anchored($patterns->versionExtended, '1.02.3'))->toBeFalse();
	});

	test('versionExtended rejects an incomplete version', function () use ($anchored): void {
		$patterns = new TotalCMSTwigPatterns();

		expect($anchored($patterns->versionExtended, '3.5'))->toBeFalse();
		expect($anchored($patterns->versionExtended, '3.5.0.1'))->toBeFalse();
		expect($anchored($patterns->versionExtended, 'banana'))->toBeFalse();
	});

	// -------------------------
	// Storage shape
	// -------------------------

	test('both patterns are stored unanchored, like every other entry', function (): void {
		// An anchored entry would break the HTML pattern attribute, which wraps
		// the value in its own anchors.
		$patterns = new TotalCMSTwigPatterns();

		foreach (['version', 'versionExtended'] as $name) {
			expect($patterns->{$name})->not->toStartWith('^');
			expect($patterns->{$name})->not->toEndWith('$');
		}
	});

	test('unanchored, they match a substring — which is why schema use needs anchors', function (): void {
		// This is the trap the docs warn about: JSON Schema `pattern` is a
		// substring match, so the bare registry value accepts junk around it.
		$patterns = new TotalCMSTwigPatterns();

		expect(preg_match('/' . $patterns->version . '/', 'junk-3.5.0-junk'))->toBe(1);
		expect(preg_match('/^' . $patterns->version . '$/', 'junk-3.5.0-junk'))->toBe(0);
	});
});
