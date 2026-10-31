<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\Nav\AdminNavRegistry;
use TotalCMS\Domain\Admin\Nav\NavEntry;
use TotalCMS\Domain\Admin\Nav\QuickActions;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
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

test('the dashboard settings form offers the catalog as a list', function (): void {
	$html = $this->c->get(TotalFormFactory::class)->field('list', 'quickActions', [
		'value'    => ['new-page'],
		'settings' => ['propertyOptions' => 'quickActions', 'addChoices' => false],
	]);

	expect($html)->toContain('name="quickActions"', 'value="new-page"', 'value="activity"');
});
