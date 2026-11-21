<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;

describe('S3 Uploads manifest', function (): void {
	$dir = dirname(__DIR__, 4) . '/resources/extensions/totalcms/s3-uploads';

	test('the manifest is valid and needs Pro', function () use ($dir): void {
		$data     = json_decode((string)file_get_contents($dir . '/extension.json'), true, flags: JSON_THROW_ON_ERROR);
		$manifest = ExtensionManifest::fromArray($data + ['version' => '3.7.0']);

		expect($manifest->id)->toBe('totalcms/s3-uploads')
			->and($manifest->minEdition)->toBe('pro')
			->and($manifest->entrypoint)->toBe('Extension.php')
			->and((new ManifestValidator($this->createMock(EditionFeatureService::class)))->validate($manifest))->toBeNull()
			->and(is_file($dir . '/' . $manifest->entrypoint))->toBeTrue()
			->and(is_file($dir . '/icon.png'))->toBeTrue();
	});

	test('the settings schema has exactly the ten settings, each placed in the form', function () use ($dir): void {
		$schema = json_decode((string)file_get_contents($dir . '/settings.json'), true, flags: JSON_THROW_ON_ERROR);
		$keys   = ['endpoint', 'region', 'bucket', 'accessKey', 'secretKey', 'prefix', 'publicBaseUrl', 'visibility', 'signedUrlTtl', 'addressing'];

		expect(array_keys($schema['properties']))->toEqualCanonicalizing($keys);
		foreach ($keys as $key) {
			expect($schema['formgrid'])->toContain($key);
		}
		expect($schema['properties']['accessKey']['field'])->toBe('secret')
			->and($schema['properties']['secretKey']['field'])->toBe('secret');
	});
});
