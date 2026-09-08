<?php

declare(strict_types=1);

use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

/**
 * Behaviour of the `cms.admin.*` helpers that had no test at all, exercised
 * through the adapter's PUBLIC API against the real container, so they keep
 * passing when the adapter is split into per-concern services (dashboard,
 * job queue, builder templates). The already-covered helpers (alerts,
 * automations, settings sections, templates by folder, job-queue info, the
 * license row) keep their unit tests.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c      = $this->app->getContainer();
	$this->admin  = $this->c->get(AdminTwigAdapter::class);
	$this->config = $this->c->get(Config::class);
});

// ─── CLI commands, cron, quick actions ───────────────────────────────────────

test('tcmsCommandPrefix() is the PHP binary plus the tcms executable, and the concrete commands append their task', function (): void {
	$prefix = $this->admin->tcmsCommandPrefix();

	expect($prefix)->toContain(PHP_BINARY)
		->and($prefix)->toContain('tcms')
		->and($this->admin->processJobQueueCommand())->toBe($prefix . ' jobs:process')
		->and($this->admin->processAutomationsCommand())->toBe($prefix . ' automations:process')
		->and($this->admin->oauthSetupCommand())->toBe($prefix . ' oauth:setup');
});

test('cronUrl() is absolute, outside /api, carries a token, and mints that token once', function (): void {
	$jobs = $this->admin->cronUrl('jobs');

	expect($jobs)->toStartWith(rtrim($this->config->url, '/') . $this->config->api . '/cron/jobs?token=')
		->and($jobs)->not->toContain('/api/cron');
	parse_str((string)parse_url($jobs, PHP_URL_QUERY), $query);
	expect($query['token'] ?? '')->not->toBe('')
		->and($this->admin->cronUrl('automations'))->toEndWith('/cron/automations?token=' . $query['token']);
});

test('quickActionButton() renders an htmx anchor with method, confirm, class, and reload or redirect handlers', function (): void {
	$html = $this->admin->quickActionButton('Rebuild', '/collections/blog/index', ['method' => 'PUT', 'confirm' => 'Sure?', 'class' => 'btn', 'reload' => true]);

	expect($html)->toStartWith('<a ')
		->and($html)->toContain('hx-put="' . rtrim($this->config->api, '/') . '/collections/blog/index"')
		->and($html)->toContain('hx-confirm="Sure?"')
		->and($html)->toContain('class="btn"')
		->and($html)->toContain('hx-on:htmx:error="QuickAction.error(this, event)"')
		->and($html)->toContain('hx-on:htmx:after:request="QuickAction.reload()"')
		->and($html)->toContain('>Rebuild</a>');

	$redirect = $this->admin->quickActionButton('Go', 'cache/clear', ['redirect' => '/admin/dashboard']);
	expect($redirect)->toContain('hx-post=')
		// Attribute values are escaped, so the quotes come out as entities.
		->and($redirect)->toContain('QuickAction.redirect(&#039;/admin/dashboard&#039;)')
		->and($redirect)->not->toContain('QuickAction.reload()');
});

// ─── dev mode, docs, edition gates ───────────────────────────────────────────

test('devModeStatus() and isDevModeActive() agree', function (): void {
	$status = $this->admin->devModeStatus();

	expect($status)->toHaveKey('enabled')
		->and((bool)$status['enabled'])->toBe($this->admin->isDevModeActive());
});

test('docsMenu() flattens resources/docs/menu.php into group/title/path rows that resolve to real pages', function (): void {
	$items = $this->admin->docsMenu();

	expect($items)->not->toBeEmpty();
	// `apis/openapi` is served from the generated spec, not a markdown file.
	$virtual = ['apis/openapi'];
	foreach ($items as $item) {
		expect($item)->toHaveKeys(['group', 'title', 'path'])
			->and($item['group'])->not->toBe('');
		if (!in_array($item['path'], $virtual, true)) {
			expect(is_file(dirname(__DIR__, 2) . '/resources/docs/' . $item['path'] . '.md'))->toBeTrue("docs menu points at a missing page: {$item['path']}");
		}
	}
	expect(array_column($items, 'path'))->toContain('fields/video');
});

test('inaccessibleCollections() and inaccessibleSchemas() are lists (empty under the test license)', function (): void {
	expect($this->admin->inaccessibleCollections())->toBeArray()
		->and($this->admin->inaccessibleSchemas())->toBeArray();
});

// ─── dashboard ───────────────────────────────────────────────────────────────

test('dashboardSystemStatus() reports runtime, cache backends, the license row and the update slot', function (): void {
	// The update checker asks the license server unless its answer is cached.
	// Seed the cache so the test never reaches the network and the update
	// slot is deterministic either way.
	$cache    = $this->c->get(CacheManager::class);
	$cacheKey = 'update_check_' . Version::number();
	$cache->storeComputedData($cacheKey, ['available' => false, 'version' => Version::number()], 60);

	$status = $this->admin->dashboardSystemStatus();

	expect($status)->toHaveKeys(['phpVersion', 'totalcmsVersion', 'cacheBackends', 'memoryLimit', 'maxExecutionTime', 'environment', 'license', 'update'])
		->and($status['phpVersion'])->toBe(PHP_VERSION)
		->and($status['cacheBackends'])->toBeArray()
		->and($status['license'])->toHaveKeys(['severity', 'message', 'daysRemaining'])
		->and($status['license']['severity'])->toBeIn(['success', 'warning', 'error', 'info'])
		->and($status['update'])->toBeNull();

	$cache->storeComputedData($cacheKey, ['available' => true, 'version' => '9.9.9', 'severity' => 'minor'], 60);
	expect($this->admin->dashboardSystemStatus()['update'])->toBe(['version' => '9.9.9', 'severity' => 'minor']);
});

test('dashboardRecentObjects() lists the newest ten objects across custom collections, newest first, with edit links', function (): void {
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'news', 'name' => 'News', 'schema' => 'blog']);
	$saver = $this->c->get(ObjectSaver::class);
	for ($i = 1; $i <= 12; $i++) {
		// The blog schema indexes its `updated`/`created` dates — that is what
		// "recent" reads (a bug had it reading `onUpdate`/`onCreate`, which no
		// reserved schema indexes, so the panel was always empty).
		$saver->saveObject('news', ['id' => "post-{$i}", 'title' => "Post {$i}", 'updated' => sprintf('2026-01-%02dT00:00:00+00:00', $i), 'created' => sprintf('2026-01-%02dT00:00:00+00:00', $i)]);
	}

	$recent = $this->admin->dashboardRecentObjects();

	// The save pipeline stamps `updated`/`created` itself (onUpdate/onCreate
	// date actions), so the authored dates above are not what gets indexed —
	// the list is capped at ten, newest first, and every row is addressable.
	expect($recent)->toHaveCount(10);
	foreach ($recent as $row) {
		expect($row)->toMatchArray(['collection' => 'news', 'collectionName' => 'News', 'schema' => 'blog'])
			->and($row['timestamp'])->not->toBe('')
			->and($row['editUrl'])->toBe('collections/news/' . $row['id'])
			->and($row['displayName'])->toBe('Post ' . substr($row['id'], 5));
	}
	$timestamps = array_column($recent, 'timestamp');
	$sorted     = $timestamps;
	rsort($sorted);
	expect($timestamps)->toBe($sorted);
});

// ─── builder templates and routes ────────────────────────────────────────────

test('builderFileTree() and builderNestedFileTree() list every builder category, nesting foldered ids', function (): void {
	if ($this->admin->templatesLocked()) {
		expect(true)->toBeTrue(); // git-managed templates: nothing can be written here

		return;
	}
	$templates = $this->c->get(TemplateSaver::class);
	$templates->saveTemplate('card', '<p>card</p>', 'pages');
	$templates->saveTemplate('blog/post', '<p>post</p>', 'pages');

	$flat = $this->admin->builderFileTree();
	expect($flat)->toHaveKeys(['layouts', 'pages', 'partials', 'macros'])
		->and($flat['pages'])->toContain(['id' => 'card', 'path' => 'pages/card'])
		->and($flat['pages'])->toContain(['id' => 'blog/post', 'path' => 'pages/blog/post']);

	$nested = $this->admin->builderNestedFileTree();
	$pages  = $nested['pages'];
	$folder = array_values(array_filter($pages, fn (array $n): bool => $n['type'] === 'folder' && $n['name'] === 'blog'));
	$file   = array_values(array_filter($pages, fn (array $n): bool => $n['type'] === 'file' && $n['name'] === 'card'));
	expect($folder)->toHaveCount(1)
		->and($folder[0]['children'][0])->toMatchArray(['type' => 'file', 'name' => 'post', 'id' => 'blog/post', 'path' => 'pages/blog/post'])
		->and($file)->toHaveCount(1)
		// Folders sort before files.
		->and(array_search($folder[0], $pages, true))->toBeLessThan(array_search($file[0], $pages, true));
});

test('builderRouteForCollection() finds the published builder page whose route matches a pretty-URL collection, and nothing otherwise', function (): void {
	$this->c->get(BuilderInstaller::class)->ensurePagesCollection();
	$pages = $this->c->get(ObjectSaver::class);
	$pages->saveObject('builder-pages', ['id' => 'blog-post', 'title' => 'Blog post', 'template' => 'post', 'route' => '/blog/{id}', 'draft' => false]);
	$pages->saveObject('builder-pages', ['id' => 'draft-post', 'title' => 'Draft', 'template' => 'post', 'route' => '/news/{id}', 'draft' => true]);

	$collections = $this->c->get(CollectionSaver::class);
	$collections->saveCollection(['id' => 'blog', 'name' => 'Blog', 'schema' => 'blog', 'url' => '/blog/', 'prettyUrl' => true]);
	$collections->saveCollection(['id' => 'news', 'name' => 'News', 'schema' => 'blog', 'url' => '/news/', 'prettyUrl' => true]);
	$collections->saveCollection(['id' => 'plain', 'name' => 'Plain', 'schema' => 'blog', 'url' => '/plain.php', 'prettyUrl' => false]);

	expect($this->admin->builderRouteForCollection('blog'))->toBe(['id' => 'blog-post', 'title' => 'Blog post', 'route' => '/blog/{id}'])
		// A draft page does not count as the collection's page.
		->and($this->admin->builderRouteForCollection('news'))->toBeNull()
		// No pretty URL, no builder route.
		->and($this->admin->builderRouteForCollection('plain'))->toBeNull()
		->and($this->admin->builderRouteForCollection('missing'))->toBeNull();
});

test('builderRouteForCollection() matches a templated collection URL segment for segment', function (): void {
	$this->c->get(BuilderInstaller::class)->ensurePagesCollection();
	$this->c->get(ObjectSaver::class)->saveObject('builder-pages', ['id' => 'cat-post', 'title' => 'Category post', 'template' => 'post', 'route' => '/blog/{category}/{id}', 'draft' => false]);
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'blog', 'name' => 'Blog', 'schema' => 'blog', 'url' => '/blog/{{ category }}/{{ id }}', 'prettyUrl' => true]);

	expect($this->admin->builderRouteForCollection('blog')['id'] ?? null)->toBe('cat-post');
});
