<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

function createReviewManager(string $extensionsDir): ExtensionManager
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $extensionsDir;

	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(false);
	$stateRepo = new ExtensionStateRepository($storage);

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);
	$settingsManager = new ExtensionSettingsManager($settingsStorage);

	$manifestValidator = new ManifestValidator(test()->createMock(TotalCMS\Domain\License\Service\EditionFeatureService::class));
	$discovery         = new ExtensionDiscovery($config, $manifestValidator, new NullLogger());
	$container         = test()->createMock(ContainerInterface::class);
	$container->method('has')->willReturn(false);

	return new ExtensionManager(
		$discovery,
		$stateRepo,
		new ExtensionDependencySorter(),
		$settingsManager,
		$container,
		new NullLogger(),
		$manifestValidator,
		testExtensionGuard(),
		testExtensionProfiler(),
	);
}

describe('ExtensionManager::getEnableReview', function (): void {
	test('getEnableReview surfaces note, risky caps, findings and flags for a risky extension', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';
		$manager     = createReviewManager($fixturesDir);
		$manager->discoverAndRegister();

		$review = $manager->getEnableReview('test-vendor/dangerous-ext');

		expect($review)->toHaveKeys(['capabilities', 'findings', 'reviewNote', 'risky', 'hasFlags']);

		// Developer-authored note flows through from the manifest.
		expect($review['reviewNote'])->not->toBe('');
		expect($review['reviewNote'])->toContain('public webhook endpoint');

		// Detected risky capability with its plain-language label.
		expect($review['capabilities'])->toHaveKey('routes:public');
		expect($review['risky'])->toHaveKey('routes:public');
		expect($review['risky']['routes:public'])->toBe('Exposes public, unauthenticated endpoints.');

		// Dangerous source pattern surfaced by the scanner.
		expect(array_column($review['findings'], 'pattern'))->toContain('shell_exec');

		expect($review['hasFlags'])->toBeTrue();
	});

	test('getEnableReview reports no flags for a clean extension', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';
		$manager     = createReviewManager($fixturesDir);
		$manager->discoverAndRegister();

		$review = $manager->getEnableReview('test-vendor/clean-ext');

		// Registers only a Twig function (non-risky) and ships no dangerous code.
		expect($review['risky'])->toBe([]);
		expect($review['findings'])->toBe([]);
		expect($review['hasFlags'])->toBeFalse();
	});

	test('getEnableReview returns empty for an unknown extension', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';
		$manager     = createReviewManager($fixturesDir);
		$manager->discoverAndRegister();

		$review = $manager->getEnableReview('no/such-extension');

		expect($review)->toBe([
			'capabilities' => [],
			'findings'     => [],
			'reviewNote'   => '',
			'risky'        => [],
			'hasFlags'     => false,
		]);
	});

	test('getEnableReview skips the source scan for bundled extensions', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';
		$manager     = createReviewManager($fixturesDir);
		$manager->discoverAndRegister();

		// Sanity: dangerous-ext is sideloaded, so its findings show.
		expect($manager->getEnableReview('test-vendor/dangerous-ext')['findings'])->not->toBe([]);

		// Re-flag the same manifest as bundled (ships reviewed with core) —
		// the identical source must now produce zero findings. Risky
		// capability FYIs are unaffected by the bundled exemption.
		$reflection = new ReflectionProperty($manager, 'discoveredManifests');
		$manifests  = $reflection->getValue($manager);
		$manifests['test-vendor/dangerous-ext'] = $manifests['test-vendor/dangerous-ext']->withBundled(true);
		$reflection->setValue($manager, $manifests);

		$review = $manager->getEnableReview('test-vendor/dangerous-ext');

		expect($review['findings'])->toBe([]);
		expect($review['risky'])->not->toBe([]);
	});
});
