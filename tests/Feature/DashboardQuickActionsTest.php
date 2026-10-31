<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\Nav\AdminNavRegistry;
use TotalCMS\Domain\Admin\Nav\NavEntry;
use TotalCMS\Domain\Admin\Nav\QuickActions;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;

/**
 * The dashboard's Quick Actions: the `dashboard.quickActions` setting picks
 * actions from a fixed catalog and orders them. Auth is off in the test env,
 * so every core action is visible.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c        = $this->app->getContainer();
	$this->config   = $this->c->get(Config::class);
	$this->registry = $this->c->get(AdminNavRegistry::class);
});

/** @return list<string> */
function quickActionIds(AdminNavRegistry $registry): array
{
	return array_map(fn (NavEntry $entry): string => $entry->id, $registry->quickActions()->visible());
}

test('the dashboard shows the original six actions until the setting is saved', function (): void {
	unset($this->config->dashboard['quickActions']);

	expect(quickActionIds($this->registry))->toBe(QuickActions::DEFAULTS);
});

test('the setting chooses the actions and their order, skipping ids that no longer exist', function (): void {
	$this->config->dashboard['quickActions'] = ['docs', 'new-page', 'gone-action', 'docs', 42];

	expect(quickActionIds($this->registry))->toBe(['docs', 'new-page']);
});

test('an empty setting shows no actions', function (): void {
	$this->config->dashboard['quickActions'] = [];

	expect($this->registry->quickActions()->visible())->toBe([]);
});

test('every catalog action links to an admin page and the New Template action to the builder', function (): void {
	$urls = [];
	foreach ($this->registry->quickActions()->items() as $entry) {
		$urls[$entry->id] = $entry->url;
	}

	expect($urls['new-template'])->toBe('builder/new')
		->and(array_unique(array_keys($urls)))->toHaveCount(count($urls));
});

test('the dashboard home renders the chosen actions in order', function (): void {
	// With three collections or fewer the home shows the new-user welcome instead.
	foreach (['qa-one', 'qa-two', 'qa-three', 'qa-four'] as $id) {
		$this->c->get(CollectionSaver::class)->saveCollection(['id' => $id, 'name' => $id, 'schema' => 'blog']);
	}
	$this->config->dashboard['quickActions'] = ['docs', 'new-collection'];

	$body = (string)get('/admin')->getBody();

	$start   = (int)strpos($body, 'class="quick-actions"');
	$section = substr($body, $start, (int)strpos($body, '</div>', $start) - $start);

	expect($section)->toContain('href="docs"', 'href="collections/new"', '--action-icon: var(--icon-docs)')
		->not->toContain('utils/logs')
		->and(strpos($section, 'href="docs"'))->toBeLessThan(strpos($section, 'href="collections/new"'));
});

test('each custom collection offers a New action that opens its add form, labeled by its item name', function (): void {
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'qa-recipes', 'name' => 'Recipes', 'schema' => 'blog', 'labelSingular' => 'Recipe']);
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'qa-notes', 'name' => 'Notes', 'schema' => 'blog']);

	$options = array_column($this->registry->quickActions()->options(), 'label', 'value');

	expect($options)->toHaveKey('new-object:qa-recipes')
		->and($options['new-object:qa-recipes'])->toBe('New Recipe (qa-recipes)')
		->and($options['new-object:qa-notes'])->toBe('New Post (qa-notes)', 'the blog schema names its items Post');

	$this->config->dashboard['quickActions'] = ['new-object:qa-recipes', 'docs', 'new-object:qa-gone'];
	$visible                                 = $this->registry->quickActions()->visible();

	expect(array_map(fn (NavEntry $entry): string => $entry->id, $visible))->toBe(['new-object:qa-recipes', 'docs'])
		->and($visible[0]->url)->toBe('collections/qa-recipes/add')
		->and($visible[0]->label)->toBe('New Recipe')
		->and($visible[0]->icon)->toBe('var(--icon-blog, var(--icon-collections))');
});

test('a collection New action is hidden from a user who cannot create objects in it', function (): void {
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'qa-recipes', 'name' => 'Recipes', 'schema' => 'blog']);
	$this->config->dashboard['quickActions'] = ['new-object:qa-recipes', 'docs'];

	$auth = $this->createMock(AuthTwigAdapter::class);
	$auth->method('canAccessCollection')->willReturnCallback(fn (string $collection, string $operation): bool => $operation !== 'create');
	$auth->method('canAccessDocs')->willReturn(true);

	$actions = new QuickActions($this->registry, $this->c->get(TranslationService::class), $this->config, $auth, $this->c->get(CollectionLister::class), $this->c->get(CollectionEditionService::class));

	expect(array_map(fn (NavEntry $entry): string => $entry->id, $actions->visible()))->toBe(['docs']);
});

test('Quick Navigation lists the New actions, one per collection under its name, but none for collections with their own editor', function (): void {
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'qa-recipes', 'name' => 'Recipes', 'schema' => 'blog', 'labelSingular' => 'Recipe']);

	$items = [];
	foreach ($this->registry->quickActions()->quickNavItems() as $item) {
		$items[$item['path']] = $item;
	}

	expect($items['collections/qa-recipes/add'])->toBe(['label' => 'New Recipe', 'section' => 'Recipes', 'path' => 'collections/qa-recipes/add', 'keywords' => 'new add create qa-recipes'])
		->and($items['collections/new']['section'])->toBe('Create')
		->and($items)->toHaveKey('schemas/new')
		->and($items)->not->toHaveKey('collections/automations/add')
		->and($items)->not->toHaveKey('collections/builder-pages/add');

	$body = (string)get('/admin/docs')->getBody();

	// The index is escaped for JavaScript: spaces and hyphens become \u0020 / \u002D.
	expect($body)->toContain('{"label":"New\u0020Recipe","section":"Recipes","path":"collections\/qa\u002Drecipes\/add"');
});

test('the dashboard settings form offers the catalog as a list', function (): void {
	$html = $this->c->get(TotalFormFactory::class)->field('list', 'quickActions', [
		'value'    => ['new-page'],
		'settings' => ['propertyOptions' => 'quickActions', 'addChoices' => false],
	]);

	expect($html)->toContain('name="quickActions"', 'value="new-page"', 'value="activity"');
});
