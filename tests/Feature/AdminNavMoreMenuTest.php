<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\Nav\AdminNavRegistry;
use TotalCMS\Domain\Admin\Nav\NavEntry;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;

/**
 * The sidebar "More" menu end to end: the registry resolves visibility through
 * the real container (auth is off in the test env, so every core item is
 * visible), the dashboard shell splits the rail by the `dashboard.moreMenu`
 * setting, the quick-nav index keeps listing demoted items, and the settings
 * form offers every item as a checkbox.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c        = $this->app->getContainer();
	$this->config   = $this->c->get(Config::class);
	$this->registry = $this->c->get(AdminNavRegistry::class);
});

function railHtml(string $body): string
{
	$start = strpos($body, 'class="dash-sidebar"');
	$end   = strpos($body, 'id="nav-more"') ?: strpos($body, 'class="user-profile"');

	return substr($body, (int)$start, (int)$end - (int)$start);
}

function moreHtml(string $body): string
{
	$start = strpos($body, 'id="nav-more"');
	if ($start === false) {
		return '';
	}

	return substr($body, $start, (int)strpos($body, '</nav>', $start) - $start);
}

test('with nothing demoted the rail shows every item and no More button', function (): void {
	$body = (string)get('/admin/collections')->getBody();

	expect(railHtml($body))->toContain('class="schemas"', 'class="docs"', 'class="settings"')
		->and($body)->not->toContain('id="nav-more"')
		// Only the current page is highlighted — the active macro must be falsy elsewhere.
		->and(substr_count(railHtml($body), 'menu-item active'))->toBe(1);
});

test('a demoted item leaves the rail and appears in the More popover, with the More button active on that page', function (): void {
	$this->config->dashboard['moreMenu'] = ['schemas'];

	$body = (string)get('/admin/collections')->getBody();
	expect(railHtml($body))->not->toContain('class="schemas"')
		->and(moreHtml($body))->toContain('class="schemas"', 'href="schemas"')
		->and($body)->toContain('popovertarget="nav-more"');

	$onSchemas = (string)get('/admin/schemas')->getBody();
	expect($onSchemas)->toMatch('/<li class="menu-item nav-more-item active"/');
});

test('the container wires the registry to the extension manager, so extension nav items reach the rail', function (): void {
	// PHP-DI skips optional constructor parameters entirely — an optional
	// ExtensionManager would silently stay null and no extension item would
	// ever show. Pin the wiring.
	$property = new ReflectionProperty(AdminNavRegistry::class, 'extensions');

	expect($property->getValue($this->registry))->toBeInstanceOf(ExtensionManager::class);
});

test('the registry splits visible items by the setting and ignores stale ids', function (): void {
	$this->config->dashboard['moreMenu'] = ['docs', 'ext:removed/ext:ext/removed'];

	$menu = $this->registry->menu();
	$ids  = fn (array $entries): array => array_map(fn (NavEntry $e): string => $e->id, $entries);

	expect($ids($menu['more']))->toBe(['docs'])
		->and($ids($menu['rail']))->not->toContain('docs')
		->and($ids($menu['rail']))->toContain('collections', 'settings');
});

test('quick-nav still lists a demoted item so it stays searchable', function (): void {
	$this->config->dashboard['moreMenu'] = ['schemas'];

	$body = (string)get('/admin/collections')->getBody();
	expect($body)->toContain('"section":"Navigation","path":"schemas"');
});

test('the dashboard settings form offers every nav item as a checkbox', function (): void {
	$html = $this->c->get(TotalFormFactory::class)->settings('dashboard');

	expect($html)->toContain('name="moreMenu"')
		->toMatch('/<input[^>]*name="moreMenu"[^>]*value="collections"/')
		->toMatch('/<input[^>]*name="moreMenu"[^>]*value="docs"/');
});

test('the navItems option source checks the stored ids', function (): void {
	// The settings form reads the saved config file, which tests must not
	// write, so exercise the field the form builds with a stored value.
	$html = $this->c->get(TotalFormFactory::class)->field('checklist', 'moreMenu', [
		'value'    => ['docs'],
		'settings' => ['propertyOptions' => 'navItems', 'sortOptions' => false],
	]);

	expect($html)->toMatch('/<input[^>]*name="moreMenu"[^>]*value="docs"[^>]*checked/')
		->not->toMatch('/<input[^>]*name="moreMenu"[^>]*value="collections"[^>]*checked/');
});
