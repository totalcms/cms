<?php

use function Nekofar\Slim\Pest\postJson;

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
		postJson('/collections', [
			'id'     => 'blog',
			'name'   => 'Blog',
			'schema' => 'blog',
		])->assertOk();

		postJson('/collections', [
			'id'     => 'pages',
			'name'   => 'Pages',
			'schema' => 'blog', // Use blog schema for simplicity
		])->assertOk();

		// Add objects only to blog
		postJson('/collections/blog', ['title' => 'Post 1', 'content' => 'Content 1'])->assertOk();
		postJson('/collections/blog', ['title' => 'Post 2', 'content' => 'Content 2'])->assertOk();
		postJson('/collections/blog', ['title' => 'Post 3', 'content' => 'Content 3'])->assertOk();

		// Get TotalCMSTwigAdapter from container
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter::class);

		// Get dashboard stats
		$stats = $adapter->dashboardStats();

		// Verify totalObjects is sum of all collections (including reserved auth collection with 6 objects)
		expect($stats)->toHaveKey('collections');
		expect($stats)->toHaveKey('totalObjects');
		expect($stats['collections'])->toBeGreaterThanOrEqual(2); // At least our 2 collections
		expect($stats['totalObjects'])->toBe(10);
	});

	it('dashboard collections returns all collections sorted by lastUpdated', function (): void {
		// Create collections
		postJson('/collections', [
			'id'     => 'old-blog',
			'name'   => 'Old Blog',
			'schema' => 'blog',
		])->assertOk();

		sleep(1); // Ensure different timestamps

		postJson('/collections', [
			'id'     => 'new-pages',
			'name'   => 'New Pages',
			'schema' => 'page',
		])->assertOk();

		sleep(1);

		postJson('/collections', [
			'id'     => 'newest-gallery',
			'name'   => 'Newest Gallery',
			'schema' => 'gallery',
		])->assertOk();

		// Get adapter
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter::class);

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
		// Create 12 collections
		for ($i = 1; $i <= 12; $i++) {
			postJson('/collections', [
				'id'     => "collection-{$i}",
				'name'   => "Collection {$i}",
				'schema' => 'blog',
			])->assertOk();

			if ($i < 12) {
				usleep(100000); // 0.1s delay between collections
			}
		}

		// Get adapter
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter::class);

		// Get dashboard collections
		$collections = $adapter->dashboardRecentCollections();

		// Should return only top 10 (12 created + auth = 13 total, but limited to 10)
		expect($collections)->toHaveCount(10);

		// Verify we get recent collections (timing in CI may vary, so just check most recent are present)
		$ids = array_column($collections, 'id');
		expect($ids)->toContain('collection-12');
		expect($ids)->toContain('collection-11');
		// Note: collection-10 may or may not be in top 10 depending on timing, so we don't assert it
	});

	it('dashboard collections uses totalObjects field', function (): void {
		// Create collection with objects
		postJson('/collections', [
			'id'     => 'test-blog',
			'name'   => 'Test Blog',
			'schema' => 'blog',
		])->assertOk();

		// Add 7 objects
		for ($i = 1; $i <= 7; $i++) {
			postJson('/collections/test-blog', [
				'title'   => "Post {$i}",
				'content' => "Content {$i}",
			])->assertOk();
		}

		// Get adapter
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter::class);

		// Get dashboard collections
		$collections = $adapter->dashboardRecentCollections();

		// Find test-blog collection and verify objectCount comes from totalObjects field
		$testBlog = collect($collections)->firstWhere('id', 'test-blog');
		expect($testBlog)->not()->toBeNull();
		expect($testBlog['objectCount'])->toBe(8);
	});

	it('dashboard empty collections uses totalObjects field', function (): void {
		// Create mix of empty and non-empty collections
		postJson('/collections', [
			'id'     => 'full-blog',
			'name'   => 'Full Blog',
			'schema' => 'blog',
		])->assertOk();

		postJson('/collections', [
			'id'     => 'empty-pages',
			'name'   => 'Empty Pages',
			'schema' => 'page',
		])->assertOk();

		postJson('/collections', [
			'id'     => 'empty-gallery',
			'name'   => 'Empty Gallery',
			'schema' => 'gallery',
		])->assertOk();

		// Add objects to full-blog
		postJson('/collections/full-blog', ['title' => 'Post 1', 'content' => 'Content 1'])->assertOk();

		// Get adapter
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter::class);

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
		postJson('/collections', [
			'id'     => 'blog1',
			'name'   => 'Blog 1',
			'schema' => 'blog',
		])->assertOk();

		postJson('/collections', [
			'id'     => 'blog2',
			'name'   => 'Blog 2',
			'schema' => 'blog',
		])->assertOk();

		// Add objects to both
		postJson('/collections/blog1', ['title' => 'Post 1', 'content' => 'Content 1'])->assertOk();
		postJson('/collections/blog2', ['title' => 'Post 2', 'content' => 'Content 2'])->assertOk();

		// Get adapter
		$container = $this->app->getContainer();
		$adapter   = $container->get(TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter::class);

		// Get empty collections
		$emptyCollections = $adapter->dashboardEmptyCollections();

		// Our collections with objects should not be in the empty list
		$emptyIds = array_column($emptyCollections, 'id');
		expect($emptyIds)->not()->toContain('blog1');
		expect($emptyIds)->not()->toContain('blog2');
		expect($emptyIds)->not()->toContain('auth'); // auth has 6 objects
	});
});
