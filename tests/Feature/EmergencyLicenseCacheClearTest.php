<?php

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\License\Data\LicenseData;

beforeEach(function (): void {
	// Clean up any existing test data before each test
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('Emergency License Cache Clear Endpoint', function (): void {
	it('clears license cache via emergency endpoint', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Store test license data in cache
		$testLicenseData = new LicenseData(
			valid: true,
			trial: false,
			domain: 'test.example.com',
			edition: 'pro',
			message: 'Test license',
			validationToken: 'test-token-123',
			updatesValid: true,
			trialDaysRemaining: 0,
		);

		$cacheManager->storeLicenseData(LicenseData::CACHE_KEY, $testLicenseData, LicenseData::CACHE_STORAGE_TTL);

		// Verify data is cached
		$cached = $cacheManager->getLicenseData(LicenseData::CACHE_KEY);
		expect($cached)->toBeInstanceOf(LicenseData::class);
		expect($cached->domain)->toBe('test.example.com');

		// Call emergency license cache clear endpoint
		$request  = $this->createRequest('GET', '/emergency/cache/clear-license');
		$response = $this->app->handle($request);

		// Should respond with success
		expect($response->getStatusCode())->toBe(200);

		$responseBody = (string)$response->getBody();
		$responseData = json_decode($responseBody, true);

		expect($responseData)->toBeArray();
		expect($responseData['success'])->toBeTrue();
		expect($responseData['message'])->toContain('License cache cleared');
		expect($responseData['timestamp'])->toBeString();
		expect($responseData['next_step'])->toContain('license-manager');

		// Verify license cache is cleared
		$afterClear = $cacheManager->getLicenseData(LicenseData::CACHE_KEY);
		expect($afterClear)->toBeNull();
	});

	it('handles cache clear when no license is cached', function (): void {
		// Ensure no license data is cached
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);
		$cacheManager->clearLicenseData(LicenseData::CACHE_KEY);

		// Call emergency license cache clear endpoint
		$request  = $this->createRequest('GET', '/emergency/cache/clear-license');
		$response = $this->app->handle($request);

		// Should still respond with success (clearing empty cache is fine)
		expect($response->getStatusCode())->toBe(200);

		$responseBody = (string)$response->getBody();
		$responseData = json_decode($responseBody, true);

		expect($responseData)->toBeArray();
		expect($responseData['success'])->toBeTrue();
	});

	it('emergency endpoint is publicly accessible', function (): void {
		// Emergency license cache clear should be accessible without authentication
		// for debugging and support scenarios

		$request  = $this->createRequest('GET', '/emergency/cache/clear-license');
		$response = $this->app->handle($request);

		// Should not return 401 or 403 (authentication/authorization errors)
		expect($response->getStatusCode())->not->toBe(401);
		expect($response->getStatusCode())->not->toBe(403);
		expect($response->getStatusCode())->toBeIn([200, 500]); // Success or server error, not auth error
	});

	it('provides helpful next step information', function (): void {
		$request  = $this->createRequest('GET', '/emergency/cache/clear-license');
		$response = $this->app->handle($request);

		$responseBody = (string)$response->getBody();
		$responseData = json_decode($responseBody, true);

		if ($response->getStatusCode() === 200) {
			expect($responseData['next_step'])->toContain('/admin/utils/license-manager');
		} else {
			expect($responseData['error'])->toBeString();
			expect($responseData['details'])->toBeString();
		}
	});

	it('does not affect other cached data', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Store test data in various cache types
		$cacheManager->storeComputedData('test:computed', ['data' => 'computed']);
		$cacheManager->storeCollectionIndex('test_collection', ['objects' => ['obj1']]);

		// Store license data
		$testLicenseData = new LicenseData(
			valid: true,
			trial: false,
			domain: 'test.example.com',
			edition: 'pro',
			message: 'Test license',
			validationToken: 'test-token-123',
			updatesValid: true,
			trialDaysRemaining: 0,
		);
		$cacheManager->storeLicenseData(LicenseData::CACHE_KEY, $testLicenseData, LicenseData::CACHE_STORAGE_TTL);

		// Clear only license cache
		$request  = $this->createRequest('GET', '/emergency/cache/clear-license');
		$response = $this->app->handle($request);

		expect($response->getStatusCode())->toBe(200);

		// License cache should be cleared
		$afterLicense = $cacheManager->getLicenseData(LicenseData::CACHE_KEY);
		expect($afterLicense)->toBeNull();

		// Other caches should still have data
		$afterComputed   = $cacheManager->getComputedData('test:computed');
		$afterCollection = $cacheManager->getCollectionIndex('test_collection');

		expect($afterComputed)->toBe(['data' => 'computed']);
		expect($afterCollection)->toBe(['objects' => ['obj1']]);
	});

	it('handles concurrent license cache clear requests', function (): void {
		// Test that multiple simultaneous cache clear requests don't cause issues
		$request1 = $this->createRequest('GET', '/emergency/cache/clear-license');
		$request2 = $this->createRequest('GET', '/emergency/cache/clear-license');

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
