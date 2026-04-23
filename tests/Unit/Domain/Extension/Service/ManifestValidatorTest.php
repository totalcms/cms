<?php

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Service\ManifestValidator;

describe('ManifestValidator', function (): void {
	test('validates a correct manifest', function (): void {
		$validator = new ManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'      => 'vendor/my-ext',
			'name'    => 'My Extension',
			'version' => '1.0.0',
		]);

		expect($validator->validate($manifest))->toBeNull();
	});

	test('rejects missing ID', function (): void {
		$validator = new ManifestValidator();
		$manifest  = ExtensionManifest::fromArray(['name' => 'Test', 'version' => '1.0.0']);

		expect($validator->validate($manifest))->toContain('id');
	});

	test('rejects missing name', function (): void {
		$validator = new ManifestValidator();
		$manifest  = ExtensionManifest::fromArray(['id' => 'vendor/test', 'version' => '1.0.0']);

		expect($validator->validate($manifest))->toContain('name');
	});

	test('rejects missing version', function (): void {
		$validator = new ManifestValidator();
		$manifest  = new ExtensionManifest(
			id: 'vendor/test',
			name: 'Test',
			description: '',
			version: '',
			requires: [],
			entrypoint: 'Extension.php',
			settingsSchema: null,
			minEdition: 'lite',
			author: [],
			license: 'MIT',
		);

		$error = $validator->validate($manifest);
		expect($error)->not->toBeNull();
		expect($error)->toContain('version');
	});

	test('rejects invalid ID format', function (): void {
		$validator = new ManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'      => 'InvalidFormat',
			'name'    => 'Test',
			'version' => '1.0.0',
		]);

		expect($validator->validate($manifest))->toContain('vendor/name');
	});

	test('rejects invalid version format', function (): void {
		$validator = new ManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'      => 'vendor/test',
			'name'    => 'Test',
			'version' => 'invalid',
		]);

		expect($validator->validate($manifest))->toContain('semver');
	});

	test('rejects invalid min_edition', function (): void {
		$validator = new ManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'          => 'vendor/test',
			'name'        => 'Test',
			'version'     => '1.0.0',
			'min_edition' => 'enterprise',
		]);

		expect($validator->validate($manifest))->toContain('min_edition');
	});

	test('allows valid min_edition values', function (): void {
		$validator = new ManifestValidator();

		foreach (['lite', 'standard', 'pro'] as $edition) {
			$manifest = ExtensionManifest::fromArray([
				'id'          => 'vendor/test',
				'name'        => 'Test',
				'version'     => '1.0.0',
				'min_edition' => $edition,
			]);

			expect($validator->validate($manifest))->toBeNull();
		}
	});
});
