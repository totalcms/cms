<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionGuard;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

/**
 * Build an ExtensionStateRepository backed by an in-memory storage map so the
 * manager and guard can share the SAME state file. ExtensionStateRepository is
 * final (cannot be doubled), so it's driven through a stateful storage mock.
 *
 * @param array<string,ExtensionState> $seed initial states keyed by extension id
 */
function quarantineStateRepo(array $seed = []): ExtensionStateRepository
{
	$files = [];
	if ($seed !== []) {
		$payload = [];
		foreach ($seed as $id => $state) {
			$payload[$id] = $state->toArray();
		}
		$files['.system/extensions.json'] = (string)json_encode($payload);
	}

	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturnCallback(
		fn (string $location): bool => isset($files[$location]),
	);
	$storage->method('read')->willReturnCallback(
		fn (string $location): string => $files[$location] ?? '',
	);
	$storage->method('write')->willReturnCallback(
		function (string $location, string $contents) use (&$files): bool {
			$files[$location] = $contents;

			return true;
		},
	);
	$storage->method('move')->willReturnCallback(
		function (string $old, string $new) use (&$files): bool {
			$files[$new] = $files[$old] ?? '';
			unset($files[$old]);

			return true;
		},
	);

	return new ExtensionStateRepository($storage);
}

/**
 * In-memory CacheManager double exposing its backing store by reference so the
 * rolling failure counter can be pre-seeded and inspected.
 *
 * @param array<string,mixed> $store passed by reference
 */
function quarantineCache(array &$store): CacheManager
{
	$cache = test()->createMock(CacheManager::class);
	$cache->method('getData')->willReturnCallback(
		fn (string $key): mixed => $store[$key] ?? null,
	);
	$cache->method('getOperationalData')->willReturnCallback(
		fn (string $key): mixed => $store[$key] ?? null,
	);
	$cache->method('storeData')->willReturnCallback(
		function (string $key, mixed $data, int $ttl = 0) use (&$store): bool {
			$store[$key] = $data;

			return true;
		},
	);

	return $cache;
}

/**
 * Build a manager wired to a specific state repo + guard so quarantine
 * enforcement and recovery can be exercised end to end.
 *
 * @return array{0: ExtensionManager, 1: ExtensionStateRepository}
 */
function quarantineManager(ExtensionStateRepository $stateRepo, ExtensionGuard $guard, ?TotalCMS\Domain\Extension\Service\ExtensionProfiler $profiler = null): array
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = dirname(__DIR__, 4) . '/fixtures';

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);
	$settingsManager = new ExtensionSettingsManager($settingsStorage);

	$manifestValidator = new ManifestValidator(test()->createMock(TotalCMS\Domain\License\Service\EditionFeatureService::class));
	$discovery         = new ExtensionDiscovery($config, $manifestValidator, new NullLogger());
	$container         = test()->createMock(ContainerInterface::class);
	$container->method('has')->willReturn(false);

	$manager = new ExtensionManager(
		$discovery,
		$stateRepo,
		new ExtensionDependencySorter(),
		$settingsManager,
		$container,
		new NullLogger(),
		$manifestValidator,
		$guard,
		$profiler ?? testExtensionProfiler(),
	);

	return [$manager, $stateRepo];
}

function quarantineGuard(ExtensionStateRepository $stateRepo, CacheManager $cache): ExtensionGuard
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = 'prod';
	$env         = new EnvironmentResolver($config, false);

	return new ExtensionGuard($env, $cache, $stateRepo, new NullLogger(), testExtensionProfiler());
}

/** Build a profiler that reads/writes the shared $cache, so metricsFor() can be exercised. */
function quarantineProfiler(CacheManager $cache): TotalCMS\Domain\Extension\Service\ExtensionProfiler
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = 'dev';
	$env         = new EnvironmentResolver($config, false);

	return new TotalCMS\Domain\Extension\Service\ExtensionProfiler($env, $cache, 0, new NullLogger());
}

describe('ExtensionManager quarantine enforcement', function (): void {
	test('a quarantined-but-enabled extension is not loaded', function (): void {
		$state = new ExtensionState(
			enabled: true,
			installedAt: '2026-01-01T00:00:00Z',
			version: '1.0.0',
			quarantine: [
				'reason'        => 'Crashed repeatedly',
				'failureCount'  => 5,
				'lastError'     => 'boom',
				'quarantinedAt' => '2026-01-01T00:00:00Z',
			],
		);
		$stateRepo = quarantineStateRepo(['test-vendor/hello-world' => $state]);

		$store     = [];
		$guard     = quarantineGuard($stateRepo, quarantineCache($store));
		[$manager] = quarantineManager($stateRepo, $guard);

		$manager->discoverAndRegister();

		// Enabled in state, but quarantined => must be held back from loading.
		expect($manager->getLoadedExtensions())->not->toHaveKey('test-vendor/hello-world');
	});

	test('a non-quarantined enabled extension still loads', function (): void {
		$state     = new ExtensionState(enabled: true, installedAt: '', version: '1.0.0');
		$stateRepo = quarantineStateRepo(['test-vendor/hello-world' => $state]);

		$store     = [];
		$guard     = quarantineGuard($stateRepo, quarantineCache($store));
		[$manager] = quarantineManager($stateRepo, $guard);

		$manager->discoverAndRegister();

		expect($manager->getLoadedExtensions())->toHaveKey('test-vendor/hello-world');
	});
});

describe('ExtensionManager re-enable recovery', function (): void {
	test('re-enabling clears quarantine and resets the failure counter', function (): void {
		$state = new ExtensionState(
			enabled: true,
			installedAt: '2026-01-01T00:00:00Z',
			version: '1.0.0',
			permissions: ['twig:functions' => true],
			quarantine: [
				'reason'        => 'Crashed repeatedly',
				'failureCount'  => 5,
				'lastError'     => 'boom',
				'quarantinedAt' => '2026-01-01T00:00:00Z',
			],
		);
		$stateRepo = quarantineStateRepo(['test-vendor/hello-world' => $state]);

		// Pre-seed a non-zero rolling failure count.
		$store     = ['extguard:fail:test-vendor/hello-world' => 5];
		$guard     = quarantineGuard($stateRepo, quarantineCache($store));
		[$manager] = quarantineManager($stateRepo, $guard);

		$manager->discoverAndRegister();
		$manager->enable('test-vendor/hello-world');

		$after = $stateRepo->getState('test-vendor/hello-world');
		expect($after)->not->toBeNull()
			->and($after->isQuarantined())->toBeFalse()
			->and($after->enabled)->toBeTrue();

		// Counter reset to 0 so a single fresh crash can't immediately re-trip.
		expect($store['extguard:fail:test-vendor/hello-world'])->toBe(0);
	});
});

describe('ExtensionManager quarantine info', function (): void {
	test('listExtensions surfaces quarantined + quarantineReason', function (): void {
		$state = new ExtensionState(
			enabled: true,
			installedAt: '',
			version: '1.0.0',
			quarantine: [
				'reason'        => 'Crashed repeatedly',
				'failureCount'  => 5,
				'lastError'     => 'kaboom',
				'quarantinedAt' => '2026-01-01T00:00:00Z',
			],
		);
		$stateRepo = quarantineStateRepo(['test-vendor/hello-world' => $state]);

		$store     = [];
		$guard     = quarantineGuard($stateRepo, quarantineCache($store));
		[$manager] = quarantineManager($stateRepo, $guard);

		$manager->discoverAndRegister();

		$info = $manager->getExtension('test-vendor/hello-world');

		expect($info)->not->toBeNull()
			->and($info['quarantined'])->toBeTrue()
			->and($info['quarantineReason'])->toBe('kaboom');
	});

	test('a healthy extension reports quarantined false and null reason', function (): void {
		$state     = new ExtensionState(enabled: true, installedAt: '', version: '1.0.0');
		$stateRepo = quarantineStateRepo(['test-vendor/hello-world' => $state]);

		$store     = [];
		$guard     = quarantineGuard($stateRepo, quarantineCache($store));
		[$manager] = quarantineManager($stateRepo, $guard);

		$manager->discoverAndRegister();

		$info = $manager->getExtension('test-vendor/hello-world');

		expect($info)->not->toBeNull()
			->and($info['quarantined'])->toBeFalse()
			->and($info['quarantineReason'])->toBeNull();
	});
});

describe('ExtensionManager health info', function (): void {
	test('surfaces null health and zero errorCount when no metrics recorded', function (): void {
		$state     = new ExtensionState(enabled: true, installedAt: '', version: '1.0.0');
		$stateRepo = quarantineStateRepo(['test-vendor/hello-world' => $state]);

		$store     = [];
		$cache     = quarantineCache($store);
		$guard     = quarantineGuard($stateRepo, $cache);
		$profiler  = quarantineProfiler($cache);
		[$manager] = quarantineManager($stateRepo, $guard, $profiler);

		$manager->discoverAndRegister();

		$info = $manager->getExtension('test-vendor/hello-world');

		expect($info)->not->toBeNull()
			->and($info['health'])->toBeNull()
			->and($info['errorCount'])->toBe(0);
	});

	test('surfaces health metrics and errorCount from cache', function (): void {
		$state     = new ExtensionState(enabled: true, installedAt: '', version: '1.0.0');
		$stateRepo = quarantineStateRepo(['test-vendor/hello-world' => $state]);

		$store = [
			'extprof:test-vendor/hello-world'       => ['lastMs' => 12.5, 'avgMs' => 9.0, 'samples' => 4],
			'extguard:fail:test-vendor/hello-world' => 3,
		];
		$cache     = quarantineCache($store);
		$guard     = quarantineGuard($stateRepo, $cache);
		$profiler  = quarantineProfiler($cache);
		[$manager] = quarantineManager($stateRepo, $guard, $profiler);

		$manager->discoverAndRegister();

		$info = $manager->getExtension('test-vendor/hello-world');

		expect($info)->not->toBeNull()
			->and($info['health'])->toBe(['lastMs' => 12.5, 'avgMs' => 9.0, 'samples' => 4])
			->and($info['errorCount'])->toBe(3);
	});
});
