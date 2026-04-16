<?php

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

function createExtensionManager(
	string $extensionsDir,
	?ExtensionStateRepository $stateRepo = null,
): ExtensionManager {
	$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $extensionsDir;

	// Use a mock storage adapter for the state repo
	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(false);

	if (!$stateRepo instanceof ExtensionStateRepository) {
		$stateRepo = new ExtensionStateRepository($storage);
	}

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);
	$settingsManager = new ExtensionSettingsManager($settingsStorage);

	$discovery = new ExtensionDiscovery($config, new ManifestValidator(), new NullLogger());
	$container = test()->createMock(ContainerInterface::class);
	$container->method('has')->willReturn(false);

	return new ExtensionManager(
		$discovery,
		$stateRepo,
		new ExtensionDependencySorter(),
		$settingsManager,
		$container,
		new NullLogger(),
	);
}

describe('ExtensionManager', function (): void {
	test('discovers extensions in fixture directory', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

		// Verify fixture directory exists (helps debug CI failures)
		expect(is_dir($fixturesDir . '/extensions'))->toBeTrue(
			"Fixture extensions directory not found at: {$fixturesDir}/extensions"
		);
		expect(is_file($fixturesDir . '/extensions/test-vendor/hello-world/extension.json'))->toBeTrue(
			"Hello world fixture not found at: {$fixturesDir}/extensions/test-vendor/hello-world/extension.json"
		);

		$manager = createExtensionManager($fixturesDir);

		$manager->discoverAndRegister();
		$manifests = $manager->getDiscoveredManifests();

		expect($manifests)->toHaveKey('test-vendor/hello-world');
		expect($manifests)->toHaveKey('test-vendor/broken-ext');
	});

	test('registers enabled extensions', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

		// Create a state repo that marks hello-world as enabled
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'test-vendor/hello-world' => [
				'enabled'      => true,
				'installed_at' => '2026-01-01T00:00:00Z',
				'version'      => '1.0.0',
				'error'        => null,
			],
		]));
		$storage->method('write')->willReturn(true);
		$stateRepo = new ExtensionStateRepository($storage);

		$manager = createExtensionManager($fixturesDir, $stateRepo);
		$manager->discoverAndRegister();

		$loaded = $manager->getLoadedExtensions();
		expect($loaded)->toHaveKey('test-vendor/hello-world');
		expect($loaded)->not->toHaveKey('test-vendor/broken-ext');
	});

	test('collects Twig functions from extensions', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'test-vendor/hello-world' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));
		$storage->method('write')->willReturn(true);
		$stateRepo = new ExtensionStateRepository($storage);

		$manager = createExtensionManager($fixturesDir, $stateRepo);
		$manager->discoverAndRegister();

		$twigFunctions = $manager->getAllTwigFunctions();
		$names         = array_map(fn (Twig\TwigFunction $fn): string => $fn->getName(), $twigFunctions);

		expect($names)->toContain('hello_world');
	});

	test('collects Twig filters from extensions', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'test-vendor/hello-world' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));
		$storage->method('write')->willReturn(true);
		$stateRepo = new ExtensionStateRepository($storage);

		$manager = createExtensionManager($fixturesDir, $stateRepo);
		$manager->discoverAndRegister();

		$filters = $manager->getAllTwigFilters();
		$names   = array_map(fn (Twig\TwigFilter $f): string => $f->getName(), $filters);

		expect($names)->toContain('shout');
	});

	test('collects CLI commands from extensions', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'test-vendor/hello-world' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));
		$storage->method('write')->willReturn(true);
		$stateRepo = new ExtensionStateRepository($storage);

		$manager = createExtensionManager($fixturesDir, $stateRepo);
		$manager->discoverAndRegister();

		$commands = $manager->getAllCommands();
		$names    = array_map(fn (Symfony\Component\Console\Command\Command $c): ?string => $c->getName(), $commands);

		expect($names)->toContain('test-vendor:hello');
	});

	test('collects admin nav items from extensions', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'test-vendor/hello-world' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));
		$storage->method('write')->willReturn(true);
		$stateRepo = new ExtensionStateRepository($storage);

		$manager = createExtensionManager($fixturesDir, $stateRepo);
		$manager->discoverAndRegister();

		$navItems = $manager->getAllAdminNavItems();

		expect($navItems)->toHaveCount(1);
		expect($navItems[0]->label)->toBe('Hello World');
	});

	test('collects event listeners from extensions', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'test-vendor/hello-world' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));
		$storage->method('write')->willReturn(true);
		$stateRepo = new ExtensionStateRepository($storage);

		$manager = createExtensionManager($fixturesDir, $stateRepo);
		$manager->discoverAndRegister();

		$listeners = $manager->getAllEventListeners();

		expect($listeners)->toHaveKey('object.created');
		expect($listeners['object.created'])->toHaveCount(1);
	});

	test('collects field types from extensions', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'test-vendor/hello-world' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));
		$storage->method('write')->willReturn(true);
		$stateRepo = new ExtensionStateRepository($storage);

		$manager = createExtensionManager($fixturesDir, $stateRepo);
		$manager->discoverAndRegister();

		$fieldTypes = $manager->getAllFieldTypes();

		expect($fieldTypes)->toHaveKey('hellofield');
	});

	test('does not load disabled extensions', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';
		$manager     = createExtensionManager($fixturesDir);

		$manager->discoverAndRegister();

		$loaded = $manager->getLoadedExtensions();
		expect($loaded)->toBe([]);
	});

	test('enable and disable changes state', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

		$written = [];
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$storage->method('write')->willReturnCallback(function (string $path, string $content) use (&$written): bool {
			$written[$path] = $content;

			return true;
		});
		$stateRepo = new ExtensionStateRepository($storage);

		$manager = createExtensionManager($fixturesDir, $stateRepo);
		$manager->discoverAndRegister();

		$manager->enable('test-vendor/hello-world');
		expect($stateRepo->isEnabled('test-vendor/hello-world'))->toBeTrue();

		$manager->disable('test-vendor/hello-world');
		expect($stateRepo->isEnabled('test-vendor/hello-world'))->toBeFalse();
	});
});
