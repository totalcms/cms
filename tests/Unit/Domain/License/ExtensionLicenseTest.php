<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\License\Service\ExtensionLicense;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

// ExtensionLicense answers "is this site licensed for that extension": the
// edition the manifest asks for, and the paid product when it names one.

beforeEach(function (): void {
	$this->manifest = fn (array $fields = []): ExtensionManifest => ExtensionManifest::fromArray($fields + ['id' => 'acme/thing', 'name' => 'Thing', 'version' => '1.0.0']);

	/** @param list<ExtensionManifest> $manifests */
	$this->license = function (string $edition, array $products, array $manifests): ExtensionLicense {
		$editions = $this->createMock(EditionFeatureService::class);
		$editions->method('getEdition')->willReturn(Edition::fromString($edition));

		$installed = [];
		foreach ($manifests as $manifest) {
			$installed[$manifest->id] = $manifest;
		}

		return new ExtensionLicense(fn (): array => $installed, $editions, entitlements($products));
	};
});

it('keeps today\'s min_edition rule for an extension with no entitlement', function (): void {
	$manifest = ($this->manifest)(['min_edition' => 'pro']);

	expect(($this->license)('standard', [], [$manifest])->has('acme/thing'))->toBeFalse()
		->and(($this->license)('pro', [], [$manifest])->has('acme/thing'))->toBeTrue()
		// No min_edition at all means every edition.
		->and(($this->license)('lite', [], [($this->manifest)()])->has('acme/thing'))->toBeTrue();
});

it('needs the edition and the entitlement when the manifest names one', function (): void {
	$manifest = ($this->manifest)(['min_edition' => 'pro', 'entitlement' => 'acme/thing-pro']);

	expect(($this->license)('pro', ['acme/thing-pro'], [$manifest])->has('acme/thing'))->toBeTrue()
		->and(($this->license)('pro', [], [$manifest])->has('acme/thing'))->toBeFalse()
		->and(($this->license)('pro', ['acme/another'], [$manifest])->has('acme/thing'))->toBeFalse()
		->and(($this->license)('standard', ['acme/thing-pro'], [$manifest])->has('acme/thing'))->toBeFalse();
});

it('is false for an unknown extension id', function (): void {
	expect(($this->license)('pro', ['acme/thing-pro'], [])->has('acme/thing'))->toBeFalse();
});

it('reads the manifests once, however often it is asked', function (): void {
	$editions = $this->createMock(EditionFeatureService::class);
	$editions->method('getEdition')->willReturn(Edition::PRO);
	$scans   = 0;
	$license = new ExtensionLicense(function () use (&$scans): array {
		$scans++;

		return ['acme/thing' => ($this->manifest)()];
	}, $editions, entitlements([]));

	// Discovery scans the disk, and an extension may ask on every request.
	expect($license->has('acme/thing'))->toBeTrue()
		->and($license->has('acme/thing'))->toBeTrue()
		->and($license->has('acme/other'))->toBeFalse()
		->and($scans)->toBe(1);
});

it('answers licensed() inside register()', function (bool $entitled): void {
	$manifest = ($this->manifest)(['min_edition' => 'pro', 'entitlement' => 'acme/thing-pro']);
	$license  = ($this->license)('pro', $entitled ? ['acme/thing-pro'] : [], [$manifest]);

	$container = $this->createMock(ContainerInterface::class);
	$container->method('get')->willReturnCallback(fn (string $id): object => $id === ExtensionLicense::class ? $license : throw new RuntimeException("unexpected {$id}"));
	$storage = $this->createMock(StorageFilesystemAdapter::class);
	$context = new ExtensionContext($manifest, '/tmp/acme-thing', $container, new ExtensionSettingsManager($storage), new NullLogger());

	$extension = new class implements ExtensionInterface {
		public ?bool $licensed = null;

		public function register(ExtensionContext $context): void
		{
			$this->licensed = $context->licensed();
		}

		public function boot(ExtensionContext $context): void
		{
		}
	};
	$extension->register($context);

	expect($extension->licensed)->toBe($entitled);
})->with(['entitled' => [true], 'not entitled' => [false]]);

it('reads the entitlement from the manifest, and none means no paid product', function (): void {
	expect(($this->manifest)(['entitlement' => 'acme/thing-pro'])->entitlement)->toBe('acme/thing-pro')
		->and(($this->manifest)()->entitlement)->toBe('');
});

it('rejects a malformed entitlement in the manifest', function (): void {
	$validator = new ManifestValidator($this->createMock(EditionFeatureService::class));

	expect($validator->validate(($this->manifest)(['entitlement' => 'Commerce'])))->toBe("Invalid entitlement 'Commerce': must be vendor/name")
		->and($validator->validate(($this->manifest)(['entitlement' => 'acme/thing-pro'])))->toBeNull()
		->and($validator->validate(($this->manifest)()))->toBeNull();
});
