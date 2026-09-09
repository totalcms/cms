<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\Nav\AdminNavRegistry;
use TotalCMS\Domain\Admin\Nav\NavEntry;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\EditionTwigAdapter;
use TotalCMS\Support\Config;

/**
 * The catalog side of the sidebar registry — what the items are and how the
 * "More menu" setting splits them. Visibility (auth + edition) is exercised
 * through the container in tests/Feature/AdminNavMoreMenuTest.php.
 */
describe('AdminNavRegistry', function (): void {
	beforeEach(function (): void {
		$translator = $this->createMock(TranslationService::class);
		$translator->method('trans')->willReturnCallback(fn (string $key): string => 'T:' . $key);

		$this->config            = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->dashboard = [];

		$this->extensions = $this->createMock(ExtensionManager::class);
		$this->extensions->method('getAllAdminNavItems')->willReturn([]);

		$this->registry = new AdminNavRegistry(
			(new ReflectionClass(AuthTwigAdapter::class))->newInstanceWithoutConstructor(),
			(new ReflectionClass(EditionTwigAdapter::class))->newInstanceWithoutConstructor(),
			$translator,
			$this->config,
			$this->extensions,
		);
	});

	test('extension items sit between Playground and Utils, where the rail has always put them', function (): void {
		$extensions = $this->createMock(ExtensionManager::class);
		$extensions->method('getAllAdminNavItems')->willReturn([
			new AdminNavItem(label: 'Hello', url: '/ext/test-vendor/hello', extensionId: 'test-vendor/hello'),
		]);
		$translator = $this->createMock(TranslationService::class);
		$translator->method('trans')->willReturnArgument(0);
		$registry = new AdminNavRegistry(
			(new ReflectionClass(AuthTwigAdapter::class))->newInstanceWithoutConstructor(),
			(new ReflectionClass(EditionTwigAdapter::class))->newInstanceWithoutConstructor(),
			$translator,
			$this->config,
			$extensions,
		);

		$ids = array_map(fn (NavEntry $e): string => $e->id, $registry->items());

		expect(array_search('ext:test-vendor/hello:ext/test-vendor/hello', $ids, true))->toBe(array_search('playground', $ids, true) + 1)
			->and($ids[array_search('utils', $ids, true) - 1])->toBe('ext:test-vendor/hello:ext/test-vendor/hello');
	});

	test('lists the core items in rail order with translated labels', function (): void {
		$ids = array_map(fn (NavEntry $e): string => $e->id, $this->registry->items());

		expect($ids)->toBe(['collections', 'schemas', 'dataviews', 'builder', 'mailer', 'automations', 'playground', 'utils', 'extensions', 'settings', 'docs'])
			->and($this->registry->items()[0]->label)->toBe('T:nav.collections')
			->and($this->registry->items()[0]->url)->toBe('collections');
	});

	test('an extension item gets a stable id from its extension and url', function (): void {
		$entry = AdminNavRegistry::fromExtension(new AdminNavItem(label: 'Hello', icon: '<svg/>', url: '/ext/test-vendor/hello', extensionId: 'test-vendor/hello'));

		expect($entry->id)->toBe('ext:test-vendor/hello:ext/test-vendor/hello')
			->and($entry->isExtension())->toBeTrue()
			->and($entry->icon)->toBe('<svg/>')
			->and($entry->slug())->toBe('ext/test-vendor/hello');
	});

	test('split moves listed ids into the More menu and keeps rail order', function (): void {
		$entries = $this->registry->items();
		$menu    = AdminNavRegistry::split($entries, ['automations', 'schemas']);

		expect(array_map(fn (NavEntry $e): string => $e->id, $menu['rail']))->not->toContain('automations', 'schemas')
			->and(array_map(fn (NavEntry $e): string => $e->id, $menu['more']))->toBe(['schemas', 'automations']);
	});

	test('split ignores ids that no longer match anything', function (): void {
		$menu = AdminNavRegistry::split($this->registry->items(), ['ext:gone/ext:ext/gone', 'nope']);

		expect($menu['more'])->toBe([])
			->and(count($menu['rail']))->toBe(11);
	});

	test('the hidden list comes from the dashboard moreMenu setting and tolerates junk', function (): void {
		$this->config->dashboard = ['moreMenu' => ['docs', 42, null]];

		expect($this->registry->hidden())->toBe(['docs']);
	});

	test('options list every catalog item as value/label pairs for the settings checklist', function (): void {
		$options = $this->registry->options();

		expect($options[0])->toBe(['value' => 'collections', 'label' => 'T:nav.collections'])
			->and(array_column($options, 'value'))->toContain('settings', 'docs');
	});
});
