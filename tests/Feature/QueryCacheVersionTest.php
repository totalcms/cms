<?php

declare(strict_types=1);

use TotalCMS\Domain\Cache\CacheManager;

/*
 * Cached query results are keyed on a per-collection version. A save to one
 * collection used to clear the cached queries of every collection, so on a
 * site with editors at work almost every query decoded its whole index.
 */

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$this->cache = $this->app->getContainer()->get(CacheManager::class);
});

function queryCacheKey(CacheManager $cache, string $collection): string
{
	return 'collection-query:' . $collection . '@' . $cache->queryVersion('collection:' . $collection);
}

test('a save to another collection keeps a cached query', function (): void {
	$this->cache->storeApiResponse(queryCacheKey($this->cache, 'qc-posts'), [], 'posts page 1');

	$this->cache->clearCollectionIndex('qc-pages');

	expect($this->cache->getApiResponse(queryCacheKey($this->cache, 'qc-posts'), []))->toBe('posts page 1');
});

test('a save to the collection retires its cached queries', function (): void {
	$this->cache->storeApiResponse(queryCacheKey($this->cache, 'qc-posts'), [], 'posts page 1');

	$this->cache->clearCollectionIndex('qc-posts');

	expect($this->cache->getApiResponse(queryCacheKey($this->cache, 'qc-posts'), []))->toBeNull();
});

test('a version stays the same until it is bumped', function (): void {
	$before = $this->cache->queryVersion('collection:qc-posts');

	expect($this->cache->queryVersion('collection:qc-posts'))->toBe($before);
	$this->cache->bumpQueryVersion('collection:qc-posts');
	expect($this->cache->queryVersion('collection:qc-posts'))->not->toBe($before);
});

test('a Data View query is retired by a save to any collection', function (): void {
	// A view can draw on several collections, so any save may change it.
	$key = 'dataview-query:qc-view@' . $this->cache->queryVersion('dataviews');
	$this->cache->storeApiResponse($key, [], 'view rows');

	$this->cache->clearCollectionIndex('qc-pages');

	expect($this->cache->getApiResponse('dataview-query:qc-view@' . $this->cache->queryVersion('dataviews'), []))->toBeNull();
});

test('a version lasts until a save, not a day', function (): void {
	// It used to expire after 24 hours, retiring every cached query on a site
	// nobody had touched. Only a save needs a new one.
	if (!function_exists('apcu_enabled') || !apcu_enabled()) {
		$this->markTestSkipped('APCu is not enabled in the CLI');
	}
	$this->cache->queryVersion('collection:qc-ttl');

	$ttl = null;
	foreach (new APCUIterator('/:ver:collection:qc-ttl$/') as $entry) {
		$ttl = $entry['ttl'];
	}

	expect($ttl)->toBe(30 * 86400);
});
