<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionGuard;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

/**
 * Build a state repository backed by an in-memory storage map. Mirrors the
 * helper in ExtensionManagerQuarantineTest — ExtensionStateRepository is final
 * (cannot be doubled), so it's driven through a stateful storage mock.
 *
 * @param array<string,ExtensionState> $seed initial states keyed by extension id
 */
function reconsentStateRepo(array $seed = []): ExtensionStateRepository
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
 * Build a manager pointed at the test fixtures so update re-consent can be
 * exercised against the real dangerous-ext / clean-ext / hello-world fixtures.
 */
function reconsentManager(ExtensionStateRepository $stateRepo): ExtensionManager
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

describe('ExtensionManager update re-consent — scanner gate', function (): void {
	test('a flagged update disables the extension and never loads the risky code', function (): void {
		// Stored at an OLDER version than the fixture manifest (1.0.0) => update.
		$state     = new ExtensionState(enabled: true, installedAt: '', version: '0.9.0');
		$stateRepo = reconsentStateRepo(['test-vendor/dangerous-ext' => $state]);
		$manager   = reconsentManager($stateRepo);

		$manager->discoverAndRegister();

		$after = $stateRepo->getState('test-vendor/dangerous-ext');
		expect($after)->not->toBeNull()
			->and($after->enabled)->toBeFalse()
			->and($after->isUpdateDisabled())->toBeTrue()
			->and($after->updateDisabled['findings'])->toBeGreaterThan(0)
			->and($after->version)->toBe('1.0.0'); // bumped so the gate won't re-trigger

		// The risky code never registered.
		expect($manager->getLoadedExtensions())->not->toHaveKey('test-vendor/dangerous-ext');
	});

	test('a clean update stays enabled, loads, and records the new version', function (): void {
		$state     = new ExtensionState(enabled: true, installedAt: '', version: '0.9.0');
		$stateRepo = reconsentStateRepo(['test-vendor/clean-ext' => $state]);
		$manager   = reconsentManager($stateRepo);

		$manager->discoverAndRegister();

		$after = $stateRepo->getState('test-vendor/clean-ext');
		expect($after)->not->toBeNull()
			->and($after->enabled)->toBeTrue()
			->and($after->isUpdateDisabled())->toBeFalse()
			->and($after->version)->toBe('1.0.0');

		expect($manager->getLoadedExtensions())->toHaveKey('test-vendor/clean-ext');
	});

	test('no version change performs no update action', function (): void {
		// Stored version MATCHES the fixture manifest => no update gate.
		$state     = new ExtensionState(enabled: true, installedAt: '', version: '1.0.0');
		$stateRepo = reconsentStateRepo(['test-vendor/dangerous-ext' => $state]);
		$manager   = reconsentManager($stateRepo);

		$manager->discoverAndRegister();

		$after = $stateRepo->getState('test-vendor/dangerous-ext');
		expect($after)->not->toBeNull()
			->and($after->enabled)->toBeTrue()
			->and($after->isUpdateDisabled())->toBeFalse();

		// No gate fired, so dangerous-ext loads normally (its dangerous code is
		// in a never-called private method — register() is side-effect free).
		expect($manager->getLoadedExtensions())->toHaveKey('test-vendor/dangerous-ext');
	});

	test('a built-in (bundled) extension is exempt from the update gate', function (): void {
		// Bundled extensions ship in the package and version with core, so even a
		// version mismatch must NOT trigger the scanner gate. Use a real bundled
		// extension (resources/extensions/totalcms/*) seeded enabled at a stale
		// version; discovery overrides its version to the running T3 version.
		$state     = new ExtensionState(enabled: true, installedAt: '', version: '0.0.1');
		$stateRepo = reconsentStateRepo(['totalcms/maintenance' => $state]);
		$manager   = reconsentManager($stateRepo);

		$manager->discoverAndRegister();

		$after = $stateRepo->getState('totalcms/maintenance');
		expect($after)->not->toBeNull()
			->and($after->enabled)->toBeTrue()              // not disabled by the gate
			->and($after->isUpdateDisabled())->toBeFalse(); // no update marker recorded
	});
});

describe('ExtensionManager update re-consent — new capabilities default OFF', function (): void {
	test('a newly-registered capability is stored as false and excluded from accessors', function (): void {
		// Seed stored permissions that are MISSING a capability the hello-world
		// fixture actually registers (twig:filters via the `shout` filter). This
		// simulates an update that gained a new capability. The version matches
		// the manifest so the scanner gate stays out of the way — only the cap
		// reconciliation runs. After boot the missing cap must land as false and
		// be filtered out of getAllTwigFilters().
		$state = new ExtensionState(
			enabled: true,
			installedAt: '',
			version: '1.0.0',
			permissions: [
				'twig:functions' => true,
				// 'twig:filters' intentionally absent — the "new" capability.
				'twig:globals'   => true,
				'cli:commands'   => true,
				'routes:api'     => true,
				'routes:public'  => true,
				'routes:admin'   => true,
				'admin:nav'      => true,
				'admin:widgets'  => true,
				'events:listen'  => true,
				'fields'         => true,
			],
		);
		$stateRepo = reconsentStateRepo(['test-vendor/hello-world' => $state]);
		$manager   = reconsentManager($stateRepo);

		$manager->discoverAndRegister();

		$after = $stateRepo->getState('test-vendor/hello-world');
		expect($after)->not->toBeNull()
			->and($after->permissions)->toHaveKey('twig:filters')
			->and($after->permissions['twig:filters'])->toBeFalse()  // new cap OFF
			->and($after->permissions['twig:functions'])->toBeTrue(); // existing choice preserved

		// The off capability is filtered out of the accessor (the `shout` filter
		// the fixture registers must not be exposed).
		$filterNames = array_map(fn (Twig\TwigFilter $f): string => $f->getName(), $manager->getAllTwigFilters());
		expect($filterNames)->not->toContain('shout');

		// A permitted capability still flows through.
		$functionNames = array_map(fn (Twig\TwigFunction $f): string => $f->getName(), $manager->getAllTwigFunctions());
		expect($functionNames)->toContain('hello_world');
	});
});
