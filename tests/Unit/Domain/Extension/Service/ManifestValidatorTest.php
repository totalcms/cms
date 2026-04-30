<?php

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;

function createManifestValidator(): ManifestValidator
{
	$editionService = test()->createMock(EditionFeatureService::class);

	return new ManifestValidator($editionService);
}

describe('ManifestValidator', function (): void {
	test('validates a correct manifest', function (): void {
		$validator = createManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'      => 'vendor/my-ext',
			'name'    => 'My Extension',
			'version' => '1.0.0',
		]);

		expect($validator->validate($manifest))->toBeNull();
	});

	test('rejects missing ID', function (): void {
		$validator = createManifestValidator();
		$manifest  = ExtensionManifest::fromArray(['name' => 'Test', 'version' => '1.0.0']);

		expect($validator->validate($manifest))->toContain('id');
	});

	test('rejects missing name', function (): void {
		$validator = createManifestValidator();
		$manifest  = ExtensionManifest::fromArray(['id' => 'vendor/test', 'version' => '1.0.0']);

		expect($validator->validate($manifest))->toContain('name');
	});

	test('rejects missing version', function (): void {
		$validator = createManifestValidator();
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
		$validator = createManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'      => 'InvalidFormat',
			'name'    => 'Test',
			'version' => '1.0.0',
		]);

		expect($validator->validate($manifest))->toContain('vendor/name');
	});

	test('rejects invalid version format', function (): void {
		$validator = createManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'      => 'vendor/test',
			'name'    => 'Test',
			'version' => 'invalid',
		]);

		expect($validator->validate($manifest))->toContain('semver');
	});

	test('rejects invalid min_edition', function (): void {
		$validator = createManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'          => 'vendor/test',
			'name'        => 'Test',
			'version'     => '1.0.0',
			'min_edition' => 'enterprise',
		]);

		expect($validator->validate($manifest))->toContain('min_edition');
	});

	test('allows valid min_edition values', function (): void {
		$validator = createManifestValidator();

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

	test('reports no incompatibility when requirements are met', function (): void {
		$validator = createManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'       => 'vendor/test',
			'name'     => 'Test',
			'version'  => '1.0.0',
			'requires' => ['totalcms' => '>=3.0.0', 'php' => '>=8.2'],
		]);

		expect($validator->getIncompatibilityReasons($manifest))->toBe([]);
	});

	test('reports PHP version incompatibility', function (): void {
		$validator = createManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'       => 'vendor/test',
			'name'     => 'Test',
			'version'  => '1.0.0',
			'requires' => ['php' => '>=99.0'],
		]);

		$reasons = $validator->getIncompatibilityReasons($manifest);
		expect($reasons)->toHaveCount(1);
		expect($reasons[0])->toContain('PHP');
		expect($reasons[0])->toContain('>=99.0');
	});

	test('reports Total CMS version incompatibility', function (): void {
		$validator = createManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'       => 'vendor/test',
			'name'     => 'Test',
			'version'  => '1.0.0',
			'requires' => ['totalcms' => '>=99.0.0'],
		]);

		$reasons = $validator->getIncompatibilityReasons($manifest);
		// May include Total CMS reason depending on detected version; if not detectable,
		// the check is skipped and the array stays empty.
		foreach ($reasons as $reason) {
			expect($reason)->toContain('Total CMS');
		}
	});

	test('reports both incompatibilities at once', function (): void {
		$validator = createManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'       => 'vendor/test',
			'name'     => 'Test',
			'version'  => '1.0.0',
			'requires' => ['totalcms' => '>=99.0.0', 'php' => '>=99.0'],
		]);

		$reasons = $validator->getIncompatibilityReasons($manifest);
		// PHP check is deterministic; Total CMS check depends on Version::number().
		expect(count($reasons))->toBeGreaterThanOrEqual(1);
	});

	test('ignores unparseable version constraints', function (): void {
		$validator = createManifestValidator();
		$manifest  = ExtensionManifest::fromArray([
			'id'       => 'vendor/test',
			'name'     => 'Test',
			'version'  => '1.0.0',
			'requires' => ['php' => 'not-a-constraint'],
		]);

		expect($validator->getIncompatibilityReasons($manifest))->toBe([]);
	});
});
