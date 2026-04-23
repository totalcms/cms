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

describe('Extension fault isolation', function (): void {
	test('broken extension boot does not prevent other extensions from loading', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

		// Enable both hello-world and broken-ext
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'test-vendor/hello-world' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
			'test-vendor/broken-ext'  => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));
		$storage->method('write')->willReturn(true);
		$stateRepo = new ExtensionStateRepository($storage);

		$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->datadir = $fixturesDir;

		$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
		$settingsStorage->method('fileExists')->willReturn(false);
		$settingsManager = new ExtensionSettingsManager($settingsStorage);

		$discovery = new ExtensionDiscovery($config, new ManifestValidator(), new NullLogger());
		$container = test()->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);

		$manager = new ExtensionManager(
			$discovery,
			$stateRepo,
			new ExtensionDependencySorter(),
			$settingsManager,
			$container,
			new NullLogger(),
		);

		$manager->discoverAndRegister();

		// Both should register (broken-ext only fails in boot, not register)
		$loaded = $manager->getLoadedExtensions();
		expect($loaded)->toHaveKey('test-vendor/hello-world');
		expect($loaded)->toHaveKey('test-vendor/broken-ext');

		$manager->bootAll();

		// After boot: hello-world should still be loaded, broken-ext should be removed
		$loaded = $manager->getLoadedExtensions();
		expect($loaded)->toHaveKey('test-vendor/hello-world');
		expect($loaded)->not->toHaveKey('test-vendor/broken-ext');

		// hello-world's Twig functions should still be available
		$twigFunctions = $manager->getAllTwigFunctions();
		$names         = array_map(fn (Twig\TwigFunction $fn): string => $fn->getName(), $twigFunctions);
		expect($names)->toContain('hello_world');
	});

	test('broken extension error is recorded in state', function (): void {
		$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

		$writtenData = [];
		$storage     = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'test-vendor/broken-ext' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));
		$storage->method('write')->willReturnCallback(function (string $path, string $content) use (&$writtenData): bool {
			$writtenData = json_decode($content, true);

			return true;
		});
		$stateRepo = new ExtensionStateRepository($storage);

		$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->datadir = $fixturesDir;

		$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
		$settingsStorage->method('fileExists')->willReturn(false);

		$discovery = new ExtensionDiscovery($config, new ManifestValidator(), new NullLogger());
		$container = test()->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);

		$manager = new ExtensionManager(
			$discovery,
			$stateRepo,
			new ExtensionDependencySorter(),
			new ExtensionSettingsManager($settingsStorage),
			$container,
			new NullLogger(),
		);

		$manager->discoverAndRegister();

		$manager->bootAll();

		// The state should have the error recorded
		expect($writtenData)->toHaveKey('test-vendor/broken-ext');
		expect($writtenData['test-vendor/broken-ext']['error'])->toContain('boot() failed');
	});
});
