<?php

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

describe('Extension route registration', function (): void {
	test('API routes are registered under /ext/{vendor}/{name}/', function (): void {
		$app     = AppFactory::create();
		$manager = createRouteTestManager();

		$manager->discoverAndRegister();
		$manager->bootAll($app);

		$routes     = $app->getRouteCollector()->getRoutes();
		$patterns   = array_map(fn ($r) => $r->getPattern(), $routes);

		expect($patterns)->toContain('/ext/test-vendor/hello-world/api/data');
	});

	test('public routes are registered under /ext/{vendor}/{name}/', function (): void {
		$app     = AppFactory::create();
		$manager = createRouteTestManager();

		$manager->discoverAndRegister();
		$manager->bootAll($app);

		$routes   = $app->getRouteCollector()->getRoutes();
		$patterns = array_map(fn ($r) => $r->getPattern(), $routes);

		expect($patterns)->toContain('/ext/test-vendor/hello-world/webhook');
	});

	test('admin routes are registered under /admin/ext/{vendor}/{name}/', function (): void {
		$app     = AppFactory::create();
		$manager = createRouteTestManager();

		$manager->discoverAndRegister();
		$manager->bootAll($app);

		$routes   = $app->getRouteCollector()->getRoutes();
		$patterns = array_map(fn ($r) => $r->getPattern(), $routes);

		expect($patterns)->toContain('/admin/ext/test-vendor/hello-world/dashboard');
	});

	test('all three route types coexist', function (): void {
		$app     = AppFactory::create();
		$manager = createRouteTestManager();

		$manager->discoverAndRegister();
		$manager->bootAll($app);

		$routes   = $app->getRouteCollector()->getRoutes();
		$patterns = array_map(fn ($r) => $r->getPattern(), $routes);

		expect($patterns)->toContain('/ext/test-vendor/hello-world/api/data');
		expect($patterns)->toContain('/ext/test-vendor/hello-world/webhook');
		expect($patterns)->toContain('/admin/ext/test-vendor/hello-world/dashboard');
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

	$discovery = new ExtensionDiscovery($config, new ManifestValidator(), new NullLogger());
	$container = test()->createMock(ContainerInterface::class);
	$container->method('has')->willReturn(false);

	return new ExtensionManager(
		$discovery,
		$stateRepo,
		new ExtensionDependencySorter(),
		new ExtensionSettingsManager($settingsStorage),
		$container,
		new NullLogger(),
	);
}
