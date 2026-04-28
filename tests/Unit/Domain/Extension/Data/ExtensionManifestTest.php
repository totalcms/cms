<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Data\ExtensionManifest;

describe('ExtensionManifest', function (): void {
	test('creates from valid array', function (): void {
		$manifest = ExtensionManifest::fromArray([
			'id'          => 'vendor/my-ext',
			'name'        => 'My Extension',
			'description' => 'A test extension',
			'version'     => '1.0.0',
			'requires'    => ['totalcms' => '>=3.3.0', 'php' => '>=8.2'],
			'entrypoint'  => 'Extension.php',
			'author'      => ['name' => 'Test', 'url' => 'https://example.com'],
			'license'     => 'MIT',
		]);

		expect($manifest->id)->toBe('vendor/my-ext');
		expect($manifest->name)->toBe('My Extension');
		expect($manifest->version)->toBe('1.0.0');
		expect($manifest->entrypoint)->toBe('Extension.php');
		expect($manifest->settingsSchema)->toBeNull();
		expect($manifest->minEdition)->toBe('lite');
	});

	test('parses min_edition from manifest', function (): void {
		$manifest = ExtensionManifest::fromArray([
			'id'          => 'vendor/pro-ext',
			'name'        => 'Pro Extension',
			'version'     => '1.0.0',
			'min_edition' => 'pro',
		]);

		expect($manifest->minEdition)->toBe('pro');
	});

	test('handles missing fields with defaults', function (): void {
		$manifest = ExtensionManifest::fromArray([]);

		expect($manifest->id)->toBe('');
		expect($manifest->name)->toBe('');
		expect($manifest->version)->toBe('0.0.0');
		expect($manifest->entrypoint)->toBe('Extension.php');
		expect($manifest->license)->toBe('proprietary');
		expect($manifest->minEdition)->toBe('lite');
		expect($manifest->requires)->toBe([]);
	});

	test('extracts vendor and short name from ID', function (): void {
		$manifest = ExtensionManifest::fromArray(['id' => 'joeworkman/seo-pro']);

		expect($manifest->vendor())->toBe('joeworkman');
		expect($manifest->shortName())->toBe('seo-pro');
	});

	test('short name falls back to full ID when no slash', function (): void {
		$manifest = ExtensionManifest::fromArray(['id' => 'simple']);

		expect($manifest->shortName())->toBe('simple');
	});

	test('returns required totalcms version', function (): void {
		$manifest = ExtensionManifest::fromArray(['requires' => ['totalcms' => '>=3.5.0']]);

		expect($manifest->requiresTotalCmsVersion())->toBe('>=3.5.0');
	});

	test('defaults totalcms requirement to >=3.0.0', function (): void {
		$manifest = ExtensionManifest::fromArray([]);

		expect($manifest->requiresTotalCmsVersion())->toBe('>=3.0.0');
	});

	test('returns required extensions', function (): void {
		$manifest = ExtensionManifest::fromArray([
			'requires' => ['extensions' => ['vendor/other' => '>=1.0.0']],
		]);

		expect($manifest->requiredExtensions())->toBe(['vendor/other' => '>=1.0.0']);
	});

	test('parses links with label and url', function (): void {
		$manifest = ExtensionManifest::fromArray([
			'links' => [
				['label' => 'Documentation', 'url' => 'https://example.com/docs'],
				['label' => 'Dashboard', 'url' => '/admin/ext/vendor/name/dashboard'],
			],
		]);

		expect($manifest->links)->toHaveCount(2);
		expect($manifest->links[0])->toBe(['label' => 'Documentation', 'url' => 'https://example.com/docs']);
		expect($manifest->links[1])->toBe(['label' => 'Dashboard', 'url' => '/admin/ext/vendor/name/dashboard']);
	});

	test('drops malformed link entries', function (): void {
		$manifest = ExtensionManifest::fromArray([
			'links' => [
				['label' => 'Good', 'url' => '/ok'],
				['label' => 'Missing url'],
				['url' => 'https://example.com/missing-label'],
				'not-an-object',
				['label' => '', 'url' => '/empty-label'],
			],
		]);

		expect($manifest->links)->toHaveCount(1);
		expect($manifest->links[0]['label'])->toBe('Good');
	});

	test('defaults links to empty list', function (): void {
		$manifest = ExtensionManifest::fromArray([]);

		expect($manifest->links)->toBe([]);
	});
});
