<?php

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Data\DashboardWidget;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use Twig\TwigFilter;
use Twig\TwigFunction;

function createTestContext(string $extensionPath = '/path/to/extension'): ExtensionContext
{
	$manifest = ExtensionManifest::fromArray([
		'id'      => 'test-vendor/test-ext',
		'name'    => 'Test Extension',
		'version' => '1.0.0',
	]);

	$container = test()->createMock(ContainerInterface::class);
	$storage   = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(false);
	$settings = new ExtensionSettingsManager($storage);

	return new ExtensionContext($manifest, $extensionPath, $container, $settings, new Psr\Log\NullLogger());
}

describe('ExtensionContext', function (): void {
	test('exposes extension identity', function (): void {
		$ctx = createTestContext();

		expect($ctx->extensionId())->toBe('test-vendor/test-ext');
		expect($ctx->extensionPath())->toBe('/path/to/extension');
		expect($ctx->manifest()->name)->toBe('Test Extension');
	});

	test('registers and retrieves Twig functions', function (): void {
		$ctx = createTestContext();
		$fn  = new TwigFunction('test_func', fn (): string => 'hello');

		$ctx->addTwigFunction($fn);

		expect($ctx->getRegisteredTwigFunctions())->toHaveCount(1);
		expect($ctx->getRegisteredTwigFunctions()[0]->getName())->toBe('test_func');
	});

	test('registers and retrieves Twig filters', function (): void {
		$ctx    = createTestContext();
		$filter = new TwigFilter('test_filter', fn (string $v): string => $v);

		$ctx->addTwigFilter($filter);

		expect($ctx->getRegisteredTwigFilters())->toHaveCount(1);
		expect($ctx->getRegisteredTwigFilters()[0]->getName())->toBe('test_filter');
	});

	test('registers and retrieves Twig globals', function (): void {
		$ctx = createTestContext();

		$ctx->addTwigGlobal('testVar', 'testValue');

		expect($ctx->getRegisteredTwigGlobals())->toBe(['testVar' => 'testValue']);
	});

	test('registers and retrieves commands', function (): void {
		$ctx = createTestContext();
		$cmd = new Symfony\Component\Console\Command\Command('test:cmd');

		$ctx->addCommand($cmd);

		expect($ctx->getRegisteredCommands())->toHaveCount(1);
		expect($ctx->getRegisteredCommands()[0]->getName())->toBe('test:cmd');
	});

	test('registers and retrieves routes', function (): void {
		$ctx    = createTestContext();
		$called = false;

		$ctx->addRoutes(function () use (&$called): void {
			$called = true;
		});

		$routes = $ctx->getRegisteredRoutes();
		expect($routes)->toHaveCount(1);

		$routes[0]();
		expect($called)->toBeTrue();
	});

	test('registers and retrieves admin nav items', function (): void {
		$ctx  = createTestContext();
		$item = new AdminNavItem(label: 'Test', icon: 'test', url: '/test');

		$ctx->addAdminNavItem($item);

		expect($ctx->getRegisteredAdminNavItems())->toHaveCount(1);
		expect($ctx->getRegisteredAdminNavItems()[0]->label)->toBe('Test');
	});

	test('registers and retrieves dashboard widgets', function (): void {
		$ctx    = createTestContext();
		$widget = new DashboardWidget(id: 'test-widget', label: 'Test', template: 'test.twig');

		$ctx->addDashboardWidget($widget);

		expect($ctx->getRegisteredDashboardWidgets())->toHaveCount(1);
		expect($ctx->getRegisteredDashboardWidgets()[0]->id)->toBe('test-widget');
	});

	test('registers and retrieves field types', function (): void {
		$ctx = createTestContext();

		$ctx->addFieldType('myfield', 'App\\Fields\\MyField');

		expect($ctx->getRegisteredFieldTypes())->toBe(['myfield' => 'App\\Fields\\MyField']);
	});

	test('registers and retrieves event listeners', function (): void {
		$ctx      = createTestContext();
		$listener = fn (array $payload): null => null;

		$ctx->addEventListener('object.created', $listener, 10);

		$listeners = $ctx->getRegisteredEventListeners();
		expect($listeners)->toHaveKey('object.created');
		expect($listeners['object.created'])->toHaveCount(1);
		expect($listeners['object.created'][0][1])->toBe(10);
	});

	test('registers and retrieves container definitions', function (): void {
		$ctx     = createTestContext();
		$factory = fn (): stdClass => new stdClass();

		$ctx->addContainerDefinition('App\\Service', $factory);

		$defs = $ctx->getRegisteredContainerDefinitions();
		expect($defs)->toHaveKey('App\\Service');
	});

	test('registers and retrieves public routes', function (): void {
		$ctx    = createTestContext();
		$called = false;

		$ctx->addPublicRoutes(function () use (&$called): void {
			$called = true;
		});

		$routes = $ctx->getRegisteredPublicRoutes();
		expect($routes)->toHaveCount(1);

		$routes[0]();
		expect($called)->toBeTrue();
	});

	test('registers and retrieves admin routes', function (): void {
		$ctx    = createTestContext();
		$called = false;

		$ctx->addAdminRoutes(function () use (&$called): void {
			$called = true;
		});

		$routes = $ctx->getRegisteredAdminRoutes();
		expect($routes)->toHaveCount(1);

		$routes[0]();
		expect($called)->toBeTrue();
	});

	test('keeps API, public, and admin routes separate', function (): void {
		$ctx = createTestContext();

		$ctx->addRoutes(fn (): null => null);
		$ctx->addRoutes(fn (): null => null);
		$ctx->addPublicRoutes(fn (): null => null);
		$ctx->addAdminRoutes(fn (): null => null);
		$ctx->addAdminRoutes(fn (): null => null);
		$ctx->addAdminRoutes(fn (): null => null);

		expect($ctx->getRegisteredRoutes())->toHaveCount(2);
		expect($ctx->getRegisteredPublicRoutes())->toHaveCount(1);
		expect($ctx->getRegisteredAdminRoutes())->toHaveCount(3);
	});

	test('admin nav item encodes raw SVG icon', function (): void {
		$item = new AdminNavItem(
			label: 'Test',
			icon: '<svg viewBox="0 0 32 32"><circle cx="16" cy="16" r="12" fill="black"/></svg>',
			url: '/admin/ext/test/dashboard',
		);

		expect($item->icon)->toContain('<svg');
		expect($item->label)->toBe('Test');
		expect($item->slug())->toBe('admin/ext/test/dashboard');
	});

	test('admin nav item defaults to empty icon', function (): void {
		$item = new AdminNavItem(label: 'Test');

		expect($item->icon)->toBe('');
	});

	test('detects capabilities from registrations', function (): void {
		$ctx = createTestContext();

		$ctx->addTwigFunction(new TwigFunction('fn', fn (): string => ''));
		$ctx->addCommand(new Symfony\Component\Console\Command\Command('test:cmd'));
		$ctx->addEventListener('object.created', fn (): null => null);

		$caps = $ctx->getCapabilities();

		expect($caps)->toHaveKey('twig:functions');
		expect($caps)->toHaveKey('cli:commands');
		expect($caps)->toHaveKey('events:listen');
		expect($caps)->not->toHaveKey('twig:filters');
		expect($caps)->not->toHaveKey('routes:api');
		expect($caps)->not->toHaveKey('admin:nav');
		expect($caps)->not->toHaveKey('fields');
	});

	test('detects all capability types', function (): void {
		$ctx = createTestContext();

		$ctx->addTwigFunction(new TwigFunction('fn', fn (): string => ''));
		$ctx->addTwigFilter(new TwigFilter('fl', fn (string $v): string => $v));
		$ctx->addTwigGlobal('g', 'val');
		$ctx->addCommand(new Symfony\Component\Console\Command\Command('cmd'));
		$ctx->addRoutes(fn (): null => null);
		$ctx->addPublicRoutes(fn (): null => null);
		$ctx->addAdminRoutes(fn (): null => null);
		$ctx->addAdminNavItem(new AdminNavItem(label: 'Nav'));
		$ctx->addDashboardWidget(new DashboardWidget(id: 'w', label: 'W', template: 't'));
		$ctx->addAdminAsset('css', 'style.css');
		$ctx->addEventListener('e', fn (): null => null);
		$ctx->addFieldType('ft', 'FT');
		$ctx->addContainerDefinition('svc', fn (): stdClass => new stdClass());

		$caps = $ctx->getCapabilities();

		expect($caps)->toHaveCount(13);
	});

	test('returns empty capabilities when nothing registered', function (): void {
		$ctx = createTestContext();

		expect($ctx->getCapabilities())->toBe([]);
	});

	test('detects schemas capability when schemas directory exists', function (): void {
		$tmpPath = sys_get_temp_dir() . '/tcms-ext-test-' . uniqid();
		mkdir($tmpPath . '/schemas', 0o777, true);

		try {
			$ctx = createTestContext($tmpPath);

			expect($ctx->getCapabilities())->toHaveKey('schemas');
		} finally {
			rmdir($tmpPath . '/schemas');
			rmdir($tmpPath);
		}
	});

	test('does not detect schemas capability when schemas directory missing', function (): void {
		$ctx = createTestContext();

		expect($ctx->getCapabilities())->not->toHaveKey('schemas');
	});

	test('installSchema is blocked below Pro edition', function (): void {
		$schemaSaver = test()->createMock(SchemaSaver::class);
		$schemaSaver->expects(test()->never())->method('saveSchema');

		$schemaRepo = test()->createMock(SchemaRepository::class);
		$schemaRepo->method('schemaExists')->willReturn(false);

		$editionService = test()->createMock(EditionFeatureService::class);
		$editionService->method('getEdition')->willReturn(Edition::LITE);

		$container = test()->createMock(ContainerInterface::class);
		$container->method('has')->willReturnMap([
			[SchemaSaver::class, true],
			[EditionFeatureService::class, true],
		]);
		$container->method('get')->willReturnMap([
			[SchemaSaver::class, $schemaSaver],
			[SchemaRepository::class, $schemaRepo],
			[EditionFeatureService::class, $editionService],
		]);

		$manifest = ExtensionManifest::fromArray(['id' => 'v/x', 'name' => 'X', 'version' => '1.0.0']);
		$storage  = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$ctx = new ExtensionContext($manifest, '/tmp', $container, new ExtensionSettingsManager($storage), new Psr\Log\NullLogger());

		$ctx->installSchema(['id' => 'demo', 'properties' => []]);
	});

	test('installSchema proceeds at Pro edition', function (): void {
		$schemaSaver = test()->createMock(SchemaSaver::class);
		$schemaSaver->expects(test()->once())->method('saveSchema');

		$schemaRepo = test()->createMock(SchemaRepository::class);
		$schemaRepo->method('schemaExists')->willReturn(false);

		$editionService = test()->createMock(EditionFeatureService::class);
		$editionService->method('getEdition')->willReturn(Edition::PRO);

		$container = test()->createMock(ContainerInterface::class);
		$container->method('has')->willReturnMap([
			[SchemaSaver::class, true],
			[EditionFeatureService::class, true],
		]);
		$container->method('get')->willReturnMap([
			[SchemaSaver::class, $schemaSaver],
			[SchemaRepository::class, $schemaRepo],
			[EditionFeatureService::class, $editionService],
		]);

		$manifest = ExtensionManifest::fromArray(['id' => 'v/x', 'name' => 'X', 'version' => '1.0.0']);
		$storage  = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$ctx = new ExtensionContext($manifest, '/tmp', $container, new ExtensionSettingsManager($storage), new Psr\Log\NullLogger());

		$ctx->installSchema(['id' => 'demo', 'properties' => []]);
	});

	test('installSchema proceeds when edition service is unavailable', function (): void {
		$schemaSaver = test()->createMock(SchemaSaver::class);
		$schemaSaver->expects(test()->once())->method('saveSchema');

		$schemaRepo = test()->createMock(SchemaRepository::class);
		$schemaRepo->method('schemaExists')->willReturn(false);

		$container = test()->createMock(ContainerInterface::class);
		$container->method('has')->willReturnMap([
			[SchemaSaver::class, true],
			[EditionFeatureService::class, false],
		]);
		$container->method('get')->willReturnMap([
			[SchemaSaver::class, $schemaSaver],
			[SchemaRepository::class, $schemaRepo],
		]);

		$manifest = ExtensionManifest::fromArray(['id' => 'v/x', 'name' => 'X', 'version' => '1.0.0']);
		$storage  = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$ctx = new ExtensionContext($manifest, '/tmp', $container, new ExtensionSettingsManager($storage), new Psr\Log\NullLogger());

		$ctx->installSchema(['id' => 'demo', 'properties' => []]);
	});

	test('capability labels covers all detectable capabilities', function (): void {
		$labels = ExtensionContext::capabilityLabels();

		expect($labels)->toHaveKey('twig:functions');
		expect($labels)->toHaveKey('twig:filters');
		expect($labels)->toHaveKey('twig:globals');
		expect($labels)->toHaveKey('routes:api');
		expect($labels)->toHaveKey('routes:public');
		expect($labels)->toHaveKey('routes:admin');
		expect($labels)->toHaveKey('cli:commands');
		expect($labels)->toHaveKey('admin:nav');
		expect($labels)->toHaveKey('admin:widgets');
		expect($labels)->toHaveKey('admin:assets');
		expect($labels)->toHaveKey('events:listen');
		expect($labels)->toHaveKey('fields');
		expect($labels)->toHaveKey('schemas');
		expect($labels)->toHaveKey('container');
	});

	test('starts with empty registrations', function (): void {
		$ctx = createTestContext();

		expect($ctx->getRegisteredTwigFunctions())->toBe([]);
		expect($ctx->getRegisteredTwigFilters())->toBe([]);
		expect($ctx->getRegisteredTwigGlobals())->toBe([]);
		expect($ctx->getRegisteredCommands())->toBe([]);
		expect($ctx->getRegisteredRoutes())->toBe([]);
		expect($ctx->getRegisteredPublicRoutes())->toBe([]);
		expect($ctx->getRegisteredAdminRoutes())->toBe([]);
		expect($ctx->getRegisteredAdminNavItems())->toBe([]);
		expect($ctx->getRegisteredDashboardWidgets())->toBe([]);
		expect($ctx->getRegisteredFieldTypes())->toBe([]);
		expect($ctx->getRegisteredEventListeners())->toBe([]);
		expect($ctx->getRegisteredContainerDefinitions())->toBe([]);
	});
});
