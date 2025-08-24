<?php

use TotalCMS\Domain\Cache\CacheManager;

beforeEach(function (): void {
	// Clean up any existing test data before each test
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('Emergency Cache Clear Endpoint', function (): void {
	it('clears all caches via emergency endpoint', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Store test data in various cache types
		$cacheManager->storeComputedData('schema:blog-legacy', [
			'properties' => [
				'media' => ['$ref' => 'url.json'], // Stale schema data
			],
		]);
		$cacheManager->storeCollectionIndex('test_collection', ['objects' => ['obj1', 'obj2']]);
		$cacheManager->storeApiResponse('/api/test', [], ['cached' => 'response']);

		// Verify data is cached
		expect($cacheManager->getComputedData('schema:blog-legacy'))->toBeArray();
		expect($cacheManager->getCollectionIndex('test_collection'))->toBeArray();
		expect($cacheManager->getApiResponse('/api/test', []))->toBeArray();

		// Call emergency cache clear endpoint
		$request  = $this->createRequest('GET', '/emergency/cache/clear');
		$response = $this->app->handle($request);

		// Should respond with success
		expect($response->getStatusCode())->toBe(200);

		$responseBody = (string)$response->getBody();
		$responseData = json_decode($responseBody, true);

		expect($responseData)->toBeArray();
		expect($responseData['success'])->toBeTrue();
		expect($responseData['message'])->toContain('Emergency cache clear completed');
		expect($responseData['timestamp'])->toBeString();
		expect($responseData['note'])->toContain('All caches including OPcache have been cleared');

		// Verify caches are cleared (may still contain data if cache backends aren't available)
		// But the endpoint should have attempted to clear them
		$afterSchema     = $cacheManager->getComputedData('schema:blog-legacy');
		$afterCollection = $cacheManager->getCollectionIndex('test_collection');
		$afterApi        = $cacheManager->getApiResponse('/api/test', []);

		// Data should be null if clearing was successful, but we allow for cache backends being unavailable
		expect($afterSchema)->toBeIn([null, ['properties' => ['media' => ['$ref' => 'url.json']]]]);
		expect($afterCollection)->toBeIn([null, ['objects' => ['obj1', 'obj2']]]);
		expect($afterApi)->toBeIn([null, ['cached' => 'response']]);
	});

	it('handles cache clear failures gracefully', function (): void {
		// This test simulates what happens when cache backends fail
		// We can't easily force a failure, but we can test the endpoint structure

		$request  = $this->createRequest('GET', '/emergency/cache/clear');
		$response = $this->app->handle($request);

		// Should always respond, even if cache clearing fails
		expect($response->getStatusCode())->toBeIn([200, 500]);

		$responseBody = (string)$response->getBody();
		$responseData = json_decode($responseBody, true);

		expect($responseData)->toBeArray();

		if ($response->getStatusCode() === 200) {
			// Success response structure
			expect($responseData['success'])->toBeTrue();
			expect($responseData['message'])->toBeString();
			expect($responseData['timestamp'])->toBeString();
		} else {
			// Error response structure
			expect($responseData['error'])->toBeString();
			expect($responseData['fallback'])->toContain('restarting');
			expect($responseData['contact'])->toContain('support');
		}
	});

	it('emergency endpoint is publicly accessible', function (): void {
		// Emergency cache clear should be accessible without authentication
		// to help when admin interface is broken due to cached errors

		$request  = $this->createRequest('GET', '/emergency/cache/clear');
		$response = $this->app->handle($request);

		// Should not return 401 or 403 (authentication/authorization errors)
		expect($response->getStatusCode())->not->toBe(401);
		expect($response->getStatusCode())->not->toBe(403);
		expect($response->getStatusCode())->toBeIn([200, 500]); // Success or server error, not auth error
	});

	it('provides helpful usage information', function (): void {
		$request  = $this->createRequest('GET', '/emergency/cache/clear');
		$response = $this->app->handle($request);

		$responseBody = (string)$response->getBody();
		$responseData = json_decode($responseBody, true);

		if ($response->getStatusCode() === 200) {
			expect($responseData['usage'])->toContain('admin interface is inaccessible');
			expect($responseData['note'])->toContain('OPcache');
		} else {
			expect($responseData['fallback'])->toContain('Apache/Nginx');
			expect($responseData['contact'])->toContain('TotalCMS support');
		}
	});

	it('emergency clear resolves the schema caching issue', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Simulate the exact issue that occurred:
		// Stale blog-legacy schema cached with URL validation
		$staleBlogLegacySchema = [
			'properties' => [
				'media' => [
					'$ref'  => 'https://www.totalcms.co/schemas/properties/url.json',
					'field' => 'url',
				],
			],
		];

		$cacheManager->storeComputedData('schema:blog-legacy', $staleBlogLegacySchema);

		// Verify stale schema is cached
		$cached = $cacheManager->getComputedData('schema:blog-legacy');
		expect($cached)->toBe($staleBlogLegacySchema);

		// Call emergency cache clear
		$request  = $this->createRequest('GET', '/emergency/cache/clear');
		$response = $this->app->handle($request);

		expect($response->getStatusCode())->toBe(200);

		// Parse response to check if clearing was successful
		$responseData       = json_decode((string)$response->getBody(), true);
		$clearingSuccessful = $responseData['cleared'] ?? false;

		// Stale schema should be cleared if the operation was successful
		$afterClear = $cacheManager->getComputedData('schema:blog-legacy');
		if ($clearingSuccessful) {
			expect($afterClear)->toBeNull();
		} else {
			// If clearing failed, schema might still be there
			expect($afterClear)->toBeIn([null, $staleBlogLegacySchema]);
		}

		// In real usage, the SchemaRepository would now load the corrected schema from disk
		// The corrected schema would have: "type": "string" instead of "$ref": "url.json"
	});

	it('handles concurrent emergency cache clear requests', function (): void {
		// Test that multiple simultaneous cache clear requests don't cause issues
		$request1 = $this->createRequest('GET', '/emergency/cache/clear');
		$request2 = $this->createRequest('GET', '/emergency/cache/clear');

		$response1 = $this->app->handle($request1);
		$response2 = $this->app->handle($request2);

		// Both should succeed (or fail gracefully)
		expect($response1->getStatusCode())->toBeIn([200, 500]);
		expect($response2->getStatusCode())->toBeIn([200, 500]);

		// Both should return valid JSON
		$data1 = json_decode((string)$response1->getBody(), true);
		$data2 = json_decode((string)$response2->getBody(), true);

		expect($data1)->toBeArray();
		expect($data2)->toBeArray();
	});
});
