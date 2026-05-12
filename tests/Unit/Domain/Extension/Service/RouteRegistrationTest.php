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

describe('Extension route registration', function (): void {
	test('API routes are matchable after boot', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		$match = $manager->matchExtensionRoute('test-vendor/hello-world', 'GET', '/api/data');

		expect($match)->not->toBeNull();
		expect($match->public)->toBeFalse();
	});

	test('public routes are matchable after boot', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		$match = $manager->matchExtensionRoute('test-vendor/hello-world', 'POST', '/webhook');

		expect($match)->not->toBeNull();
		expect($match->public)->toBeTrue();
	});

	test('admin routes are matchable after boot', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		$match = $manager->matchExtensionAdminRoute('test-vendor/hello-world', 'GET', '/dashboard');

		expect($match)->not->toBeNull();
	});

	test('unknown route returns null', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		$match = $manager->matchExtensionRoute('test-vendor/hello-world', 'GET', '/nonexistent');

		expect($match)->toBeNull();
	});

	test('disabled extension routes return null', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		$match = $manager->matchExtensionRoute('test-vendor/broken-ext', 'GET', '/anything');

		expect($match)->toBeNull();
	});
});

function createRouteTestManager(): ExtensionManager
{
	$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(true);
	$storage->method('read')->willReturn(json_encode([
		'test-vendor/hello-world' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
	]));
	$storage->method('write')->willReturn(true);
	$stateRepo = new ExtensionStateRepository($storage);

	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $fixturesDir;

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);

	$manifestValidator = new ManifestValidator(test()->createMock(TotalCMS\Domain\License\Service\EditionFeatureService::class));
	$discovery         = new ExtensionDiscovery($config, $manifestValidator, new NullLogger());
	$container         = test()->createMock(ContainerInterface::class);
	$container->method('has')->willReturn(false);

	return new ExtensionManager(
		$discovery,
		$stateRepo,
		new ExtensionDependencySorter(),
		new ExtensionSettingsManager($settingsStorage),
		$container,
		new NullLogger(),
		$manifestValidator,
	);
}
