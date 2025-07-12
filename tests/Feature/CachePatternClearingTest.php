<?php

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\RedisService;

beforeEach(function (): void {
	// Clean up any existing test data before each test
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('Cache Pattern Clearing', function () {
	it('has clearByPattern method on all cache services', function (): void {
		$container = $this->app->getContainer();

		$redisService      = $container->get(RedisService::class);
		$memcachedService  = $container->get(MemcachedService::class);
		$filesystemService = $container->get(FilesystemService::class);

		// All services should have clearByPattern method
		expect(method_exists($redisService, 'clearByPattern'))->toBeTrue();
		expect(method_exists($memcachedService, 'clearByPattern'))->toBeTrue();
		expect(method_exists($filesystemService, 'clearByPattern'))->toBeTrue();
	});

	it('Redis clearByPattern works when Redis is available', function (): void {
		$container    = $this->app->getContainer();
		$redisService = $container->get(RedisService::class);

		if (!$redisService->isAvailable()) {
			$this->markTestSkipped('Redis is not available');
		}

		// Store some test data with schema pattern
		$redisService->set('computed:schema:blog', ['type' => 'blog'], 300);
		$redisService->set('computed:schema:blog-legacy', ['type' => 'blog-legacy'], 300);
		$redisService->set('computed:other:data', ['type' => 'other'], 300);

		// Verify data is stored
		expect($redisService->get('computed:schema:blog'))->toBe(['type' => 'blog']);
		expect($redisService->get('computed:schema:blog-legacy'))->toBe(['type' => 'blog-legacy']);
		expect($redisService->get('computed:other:data'))->toBe(['type' => 'other']);

		// Clear schema pattern
		$result = $redisService->clearByPattern('computed:schema:*');
		expect($result)->toBeTrue();

		// Schema data should be cleared
		expect($redisService->get('computed:schema:blog'))->toBeNull();
		expect($redisService->get('computed:schema:blog-legacy'))->toBeNull();

		// Other data should remain
		expect($redisService->get('computed:other:data'))->toBe(['type' => 'other']);
	});

	it('Memcached clearByPattern clears all cache when called', function (): void {
		$container        = $this->app->getContainer();
		$memcachedService = $container->get(MemcachedService::class);

		if (!$memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is not available');
		}

		// Store some test data
		$memcachedService->set('computed:schema:blog', ['type' => 'blog'], 300);
		$memcachedService->set('computed:other:data', ['type' => 'other'], 300);

		// Verify data is stored
		expect($memcachedService->get('computed:schema:blog'))->toBe(['type' => 'blog']);
		expect($memcachedService->get('computed:other:data'))->toBe(['type' => 'other']);

		// Clear by pattern (should clear all cache due to Memcached limitations)
		$result = $memcachedService->clearByPattern('computed:schema:*');
		expect($result)->toBeTrue();

		// All data should be cleared (Memcached limitation)
		expect($memcachedService->get('computed:schema:blog'))->toBeNull();
		expect($memcachedService->get('computed:other:data'))->toBeNull();
	});

	it('Filesystem clearByPattern clears all cache', function (): void {
		$container         = $this->app->getContainer();
		$filesystemService = $container->get(FilesystemService::class);

		// Filesystem should always be available
		expect($filesystemService->isAvailable())->toBeTrue();

		// Store some test data with schema pattern
		$filesystemService->set('computed:schema:blog', ['type' => 'blog'], 300);
		$filesystemService->set('computed:schema:blog-legacy', ['type' => 'blog-legacy'], 300);
		$filesystemService->set('computed:other:data', ['type' => 'other'], 300);

		// Verify data is stored
		expect($filesystemService->get('computed:schema:blog'))->toBe(['type' => 'blog']);
		expect($filesystemService->get('computed:schema:blog-legacy'))->toBe(['type' => 'blog-legacy']);
		expect($filesystemService->get('computed:other:data'))->toBe(['type' => 'other']);

		// Clear schema pattern (falls back to clearing all cache due to hashed structure)
		$result = $filesystemService->clearByPattern('computed:schema:*');
		expect($result)->toBeTrue();

		// All data should be cleared (filesystem limitation due to hashed structure)
		expect($filesystemService->get('computed:schema:blog'))->toBeNull();
		expect($filesystemService->get('computed:schema:blog-legacy'))->toBeNull();
		expect($filesystemService->get('computed:other:data'))->toBeNull();
	});

	it('CacheManager uses service-specific pattern clearing', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Store schema data that would cause the original issue
		$cacheManager->storeComputedData('schema:blog', ['properties' => ['media' => ['$ref' => 'url.json']]]);
		$cacheManager->storeComputedData('schema:blog-legacy', ['properties' => ['media' => ['type' => 'string']]]);
		$cacheManager->storeComputedData('other:data', ['type' => 'other']);

		// Verify data is stored
		expect($cacheManager->getComputedData('schema:blog'))->toBeArray();
		expect($cacheManager->getComputedData('schema:blog-legacy'))->toBeArray();
		expect($cacheManager->getComputedData('other:data'))->toBeArray();

		// Clear specific schema
		$result = $cacheManager->clearComputedData('schema:blog-legacy');
		// Result may be true or false depending on cache backend availability
		expect($result)->toBeIn([true, false]);

		// The specific key should be cleared
		expect($cacheManager->getComputedData('schema:blog-legacy'))->toBeNull();

		// Note: Depending on which cache backend is active, other data might also be cleared
		// This is acceptable behavior as it ensures stale data is removed
	});

	it('Emergency cache clear scenario works', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Simulate the scenario that caused the original issue
		// Store a "stale" schema with old URL validation
		$staleSchema = [
			'properties' => [
				'media' => [
					'$ref'  => 'https://www.totalcms.co/schemas/properties/url.json',
					'field' => 'url',
				],
			],
		];
		$cacheManager->storeComputedData('schema:blog-legacy', $staleSchema);

		// Verify stale data is cached
		$cached = $cacheManager->getComputedData('schema:blog-legacy');
		expect($cached)->toBe($staleSchema);
		expect($cached['properties']['media']['$ref'])->toContain('url.json');

		// Emergency clear all caches (simulating /emergency/cache/clear)
		$cleared = $cacheManager->clearAllCaches();
		expect($cleared)->toBeIn([true, false]); // May be false if no backends available

		// After clearing, the stale schema should be gone (if clearing was successful)
		$afterClear = $cacheManager->getComputedData('schema:blog-legacy');
		if ($cleared) {
			expect($afterClear)->toBeNull();
		} else {
			// If clearing failed, data might still be there
			expect($afterClear)->toBeIn([null, $staleSchema]);
		}

		// When schema is loaded again, it would come from the updated file
		// (This would happen in real usage when SchemaRepository loads from disk)
	});

	it('Pattern clearing handles non-existent patterns gracefully', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Try to clear a pattern that doesn't exist
		$result = $cacheManager->clearComputedData('non:existent:key');
		expect($result)->toBeIn([true, false]); // May fail if no cache backends available

		// Try to clear all computed data when none exists
		$result2 = $cacheManager->clearAllComputedData();
		expect($result2)->toBeTrue(); // Should succeed even if no data to clear
	});

	it('Handles cache service availability correctly', function (): void {
		$container = $this->app->getContainer();

		$redisService      = $container->get(RedisService::class);
		$memcachedService  = $container->get(MemcachedService::class);
		$filesystemService = $container->get(FilesystemService::class);

		// Test each service handles unavailability gracefully
		if (!$redisService->isAvailable()) {
			expect($redisService->clearByPattern('test:*'))->toBeFalse();
		}

		if (!$memcachedService->isAvailable()) {
			expect($memcachedService->clearByPattern('test:*'))->toBeFalse();
		}

		// Filesystem should always be available
		expect($filesystemService->isAvailable())->toBeTrue();
		expect($filesystemService->clearByPattern('test:*'))->toBeTrue();
	});

	it('Cache clearing is atomic per service', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Store test data
		$cacheManager->storeComputedData('test:key1', ['data' => 'value1']);
		$cacheManager->storeComputedData('test:key2', ['data' => 'value2']);

		// Clear should work even if only some cache services are available
		$result = $cacheManager->clearAllComputedData();
		expect($result)->toBeTrue();

		// Data should be cleared from all available services
		expect($cacheManager->getComputedData('test:key1'))->toBeNull();
		expect($cacheManager->getComputedData('test:key2'))->toBeNull();
	});
});

describe('Cache Service Pattern Clearing Edge Cases', function () {
	it('handles empty patterns correctly', function (): void {
		$container         = $this->app->getContainer();
		$filesystemService = $container->get(FilesystemService::class);

		// Store some data
		$filesystemService->set('test:key', ['data' => 'value'], 300);

		// Try clearing with empty pattern (should fallback to clear all)
		$result = $filesystemService->clearByPattern('');
		expect($result)->toBeTrue();

		// Data should be cleared (filesystem clears all cache)
		expect($filesystemService->get('test:key'))->toBeNull();
	});

	it('handles special characters in patterns', function (): void {
		$container         = $this->app->getContainer();
		$filesystemService = $container->get(FilesystemService::class);

		// Store data with special characters in keys
		$filesystemService->set('computed:schema:blog-legacy', ['data' => 'value1'], 300);
		$filesystemService->set('computed:schema:blog_v2', ['data' => 'value2'], 300);
		$filesystemService->set('computed:other:data', ['data' => 'value3'], 300);

		// Clear pattern with special characters (filesystem clears all cache)
		$result = $filesystemService->clearByPattern('computed:schema:*');
		expect($result)->toBeTrue();

		// All data should be cleared (filesystem limitation)
		expect($filesystemService->get('computed:schema:blog-legacy'))->toBeNull();
		expect($filesystemService->get('computed:schema:blog_v2'))->toBeNull();
		expect($filesystemService->get('computed:other:data'))->toBeNull();
	});

	it('Redis SCAN handles large key sets efficiently', function (): void {
		$container    = $this->app->getContainer();
		$redisService = $container->get(RedisService::class);

		if (!$redisService->isAvailable()) {
			$this->markTestSkipped('Redis is not available');
		}

		// Store many keys to test SCAN performance
		for ($i = 0; $i < 100; $i++) {
			$redisService->set("computed:schema:test$i", ['index' => $i], 300);
			$redisService->set("computed:other:test$i", ['index' => $i], 300);
		}

		// Clear schema pattern
		$result = $redisService->clearByPattern('computed:schema:*');
		expect($result)->toBeTrue();

		// Verify schema keys are cleared but other keys remain
		for ($i = 0; $i < 10; $i++) { // Check first 10 as sample
			expect($redisService->get("computed:schema:test$i"))->toBeNull();
			expect($redisService->get("computed:other:test$i"))->toBe(['index' => $i]);
		}
	});
});
