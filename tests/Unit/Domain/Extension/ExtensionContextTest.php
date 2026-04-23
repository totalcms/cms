<?php

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Data\DashboardWidget;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use Twig\TwigFilter;
use Twig\TwigFunction;

function createTestContext(): ExtensionContext
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

	return new ExtensionContext($manifest, '/path/to/extension', $container, $settings, new \Psr\Log\NullLogger());
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

		$ctx->addRoutes(fn () => null);
		$ctx->addRoutes(fn () => null);
		$ctx->addPublicRoutes(fn () => null);
		$ctx->addAdminRoutes(fn () => null);
		$ctx->addAdminRoutes(fn () => null);
		$ctx->addAdminRoutes(fn () => null);

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
