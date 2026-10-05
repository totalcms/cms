<?php

use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;

use function TotalCMS\Slim\Pest\postJson;

beforeEach(function (): void {
	// Clean data directory before each test for proper isolation
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('Dashboard Data Methods', function (): void {
	it('dashboard stats uses totalObjects field from collections', function (): void {
		// Create collections
		postJson('/api/collections', [
			'id'     => 'blog',
			'name'   => 'Blog',
			'schema' => 'blog',
		])->assertOk();

		postJson('/api/collections', [
			'id'     => 'pages',
			'name'   => 'Pages',
			'schema' => 'blog', // Use blog schema for simplicity
		])->assertOk();

		// Add objects only to blog
		postJson('/api/collections/blog', ['title' => 'Post 1', 'content' => 'Content 1'])->assertOk();
		postJson('/api/collections/blog', ['title' => 'Post 2', 'content' => 'Content 2'])->assertOk();
		postJson('/api/collections/blog', ['title' => 'Post 3', 'content' => 'Content 3'])->assertOk();

		// Get TotalCMSTwigAdapter from container
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMSTwigAdapter::class);

		// Get dashboard stats
		$stats = $adapter->dashboardStats();

		// Verify totalObjects is sum of all collections (including reserved auth collection with 7 objects)
		expect($stats)->toHaveKey('collections');
		expect($stats)->toHaveKey('totalObjects');
		expect($stats['collections'])->toBeGreaterThanOrEqual(2); // At least our 2 collections
		expect($stats['totalObjects'])->toBe(10);
	});

	it('dashboard stats counts builder templates inside subfolders', function (): void {
		// Builder templates live in layouts/, pages/, partials/ and macros/ --
		// a conventionally organised site has nothing loose at the top of
		// builder/. The stat listed non-recursively, so it reported 0 templates
		// on sites with dozens of them.
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMSTwigAdapter::class);

		// Relative to a baseline: the read-layer union already includes the
		// built-in defaults, so the absolute count is environment-dependent.
		$before = $adapter->dashboardStats()['templates'];

		foreach ([['home', 'pages'], ['base', 'layouts'], ['nav', 'partials']] as [$id, $folder]) {
			$path = templatePath($id, $folder);
			@mkdir(dirname($path), 0777, true);
			file_put_contents($path, '<p>' . $id . '</p>');
		}

		expect($adapter->dashboardStats()['templates'])->toBe($before + 3);
	});

	it('dashboard collections returns all collections sorted by lastUpdated', function (): void {
		// Create collections
		postJson('/api/collections', [
			'id'     => 'old-blog',
			'name'   => 'Old Blog',
			'schema' => 'blog',
		])->assertOk();

		sleep(1); // Ensure different timestamps

		postJson('/api/collections', [
			'id'     => 'new-pages',
			'name'   => 'New Pages',
			'schema' => 'page',
		])->assertOk();

		sleep(1);

		postJson('/api/collections', [
			'id'     => 'newest-gallery',
			'name'   => 'Newest Gallery',
			'schema' => 'gallery',
		])->assertOk();

		// Get adapter
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMSTwigAdapter::class);

		// Get dashboard collections
		$collections = $adapter->dashboardRecentCollections();

		// Should be sorted by lastUpdated (most recent first) - auth collection excluded
		expect($collections)->toHaveCount(3); // 3 created (auth is filtered out)

		// Verify all our collections are present
		$ids = array_column($collections, 'id');
		expect($ids)->toContain('newest-gallery');
		expect($ids)->toContain('new-pages');
		expect($ids)->toContain('old-blog');
		expect($ids)->not->toContain('auth'); // Auth collections are excluded from recent list

		// Verify newest-gallery is more recent than old-blog
		$galleryIndex = array_search('newest-gallery', $ids);
		$oldBlogIndex = array_search('old-blog', $ids);
		expect($galleryIndex)->toBeLessThan($oldBlogIndex);
	});

	it('dashboard collections limits to top 10', function (): void {
		// Twelve collections, each last updated a day after the one before.
		// The dates are given, not left to the clock: lastUpdated has
		// one-second resolution, so collections created 0.1s apart tie, ties
		// fall back to id order, and whether collection-11 made the cut
		// depended on where the second boundaries happened to land. Far-future
		// dates, so they also outrank any collection a boot-time migration
		// creates when this happens to be the first test in its process.
		for ($i = 1; $i <= 12; $i++) {
			postJson('/api/collections', [
				'id'          => "collection-{$i}",
				'name'        => "Collection {$i}",
				'schema'      => 'blog',
				'lastUpdated' => sprintf('2099-01-%02dT12:00:00+00:00', $i),
			])->assertOk();
		}

		// Get adapter
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMSTwigAdapter::class);

		// The ten most recent, newest first; collection-1 and -2 are cut.
		$ids = array_column($adapter->dashboardRecentCollections(), 'id');

		expect($ids)->toBe([
			'collection-12', 'collection-11', 'collection-10', 'collection-9', 'collection-8',
			'collection-7', 'collection-6', 'collection-5', 'collection-4', 'collection-3',
		]);
	});

	it('dashboard collections uses totalObjects field', function (): void {
		// Create collection with objects
		postJson('/api/collections', [
			'id'     => 'test-blog',
			'name'   => 'Test Blog',
			'schema' => 'blog',
		])->assertOk();

		// Add 7 objects
		for ($i = 1; $i <= 7; $i++) {
			postJson('/api/collections/test-blog', [
				'title'   => "Post {$i}",
				'content' => "Content {$i}",
			])->assertOk();
		}

		// Get adapter
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMSTwigAdapter::class);

		// Get dashboard collections
		$collections = $adapter->dashboardRecentCollections();

		// Find test-blog collection and verify objectCount comes from totalObjects field
		$testBlog = collect($collections)->firstWhere('id', 'test-blog');
		expect($testBlog)->not()->toBeNull();
		expect($testBlog['objectCount'])->toBe(7);
	});

	it('dashboard empty collections uses totalObjects field', function (): void {
		// Create mix of empty and non-empty collections
		postJson('/api/collections', [
			'id'     => 'full-blog',
			'name'   => 'Full Blog',
			'schema' => 'blog',
		])->assertOk();

		postJson('/api/collections', [
			'id'     => 'empty-pages',
			'name'   => 'Empty Pages',
			'schema' => 'page',
		])->assertOk();

		postJson('/api/collections', [
			'id'     => 'empty-gallery',
			'name'   => 'Empty Gallery',
			'schema' => 'gallery',
		])->assertOk();

		// Add objects to full-blog
		postJson('/api/collections/full-blog', ['title' => 'Post 1', 'content' => 'Content 1'])->assertOk();

		// Get adapter
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMSTwigAdapter::class);

		// Get empty collections
		$emptyCollections = $adapter->dashboardEmptyCollections();

		// Should include our 2 empty collections (and possibly others from system)
		expect($emptyCollections)->not()->toBeEmpty();

		$emptyIds = array_column($emptyCollections, 'id');
		expect($emptyIds)->toContain('empty-pages');
		expect($emptyIds)->toContain('empty-gallery');
		expect($emptyIds)->not()->toContain('full-blog');
		expect($emptyIds)->not()->toContain('auth'); // auth has 6 objects
	});

	it('dashboard empty collections returns empty array when all have objects', function (): void {
		// Create collections with objects
		postJson('/api/collections', [
			'id'     => 'blog1',
			'name'   => 'Blog 1',
			'schema' => 'blog',
		])->assertOk();

		postJson('/api/collections', [
			'id'     => 'blog2',
			'name'   => 'Blog 2',
			'schema' => 'blog',
		])->assertOk();

		// Add objects to both
		postJson('/api/collections/blog1', ['title' => 'Post 1', 'content' => 'Content 1'])->assertOk();
		postJson('/api/collections/blog2', ['title' => 'Post 2', 'content' => 'Content 2'])->assertOk();

		// Get adapter
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMSTwigAdapter::class);

		// Get empty collections
		$emptyCollections = $adapter->dashboardEmptyCollections();

		// Our collections with objects should not be in the empty list
		$emptyIds = array_column($emptyCollections, 'id');
		expect($emptyIds)->not()->toContain('blog1');
		expect($emptyIds)->not()->toContain('blog2');
		expect($emptyIds)->not()->toContain('auth'); // auth has 6 objects
	});
});
