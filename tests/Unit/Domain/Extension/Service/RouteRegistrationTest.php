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

	test('admin routes default to admin-only permission', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		$match = $manager->matchExtensionAdminRoute('test-vendor/hello-world', 'GET', '/dashboard');

		expect($match->permission)->toBe('admin');
	});

	test('admin routes registered with permission any keep it', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		$match = $manager->matchExtensionAdminRoute('test-vendor/hello-world', 'GET', '/everyone');

		expect($match->permission)->toBe('any');
	});

	test('placeholder routes match and capture params', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		$match = $manager->matchExtensionRoute('test-vendor/hello-world', 'GET', '/s/abc123');

		expect($match)->not->toBeNull();
		expect($match->params)->toBe(['id' => 'abc123']);
	});

	test('static routes have empty params', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		$match = $manager->matchExtensionRoute('test-vendor/hello-world', 'GET', '/api/data');

		expect($match)->not->toBeNull();
		expect($match->params)->toBe([]);
	});

	test('regex-constrained placeholder matches only valid values', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		expect($manager->matchExtensionRoute('test-vendor/hello-world', 'GET', '/items/42'))->not->toBeNull();
		// 'abc' fails the {id:\d+} constraint
		expect($manager->matchExtensionRoute('test-vendor/hello-world', 'GET', '/items/abc'))->toBeNull();
	});

	test('static path wins over placeholder regardless of registration order', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		// /embed/{id} is registered BEFORE /embed/list in the fixture; the
		// exact static match must still win (empty params, not ['id' => 'list']).
		$match = $manager->matchExtensionRoute('test-vendor/hello-world', 'GET', '/embed/list');

		expect($match)->not->toBeNull();
		expect($match->params)->toBe([]);

		// A non-static id still resolves to the dynamic route.
		$dynamic = $manager->matchExtensionRoute('test-vendor/hello-world', 'GET', '/embed/xyz');
		expect($dynamic->params)->toBe(['id' => 'xyz']);
	});

	test('placeholder does not match across slash boundaries', function (): void {
		$manager = createRouteTestManager();
		$manager->discoverAndRegister();
		$manager->bootAll();

		// {id} captures a single segment — /s/a/b must not match /s/{id}
		$match = $manager->matchExtensionRoute('test-vendor/hello-world', 'GET', '/s/a/b');

		expect($match)->toBeNull();
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
		testExtensionGuard(),
		testExtensionProfiler(),
	);
}
