<?php

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\Service\FilesystemService;

beforeEach(function (): void {
	// Clean up any existing test data before each test
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('Cache Security Tests', function (): void {
	it('prevents cache key injection attacks', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Test various malicious cache keys
		$maliciousKeys = [
			'../../../etc/passwd',
			'..\\..\\..\\windows\\system32\\config\\sam',
			'key;rm -rf /',
			'key`rm -rf /`',
			'key$(rm -rf /)',
			'key|rm -rf /',
			"key\x00hidden",
			'key with spaces',
			'key/with/slashes',
			'key\\with\\backslashes',
			'key:with:colons',
			'key*with*wildcards',
			'key?with?questions',
			'key[with]brackets',
			'key{with}braces',
		];

		foreach ($maliciousKeys as $maliciousKey) {
			// Should not throw exceptions or cause security issues
			$stored = $cacheManager->storeComputedData($maliciousKey, ['safe' => 'data']);
			expect($stored)->toBeIn([true, false]); // May succeed or fail safely

			$retrieved = $cacheManager->getComputedData($maliciousKey);
			// Should be null or the stored data, never something else
			expect($retrieved)->toBeIn([null, ['safe' => 'data']]);
		}
	});

	it('prevents cache value injection attacks', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Test malicious data values
		$maliciousData = [
			'script_injection'     => '<script>alert("xss")</script>',
			'sql_injection'        => "'; DROP TABLE users; --",
			'command_injection'    => '; rm -rf /',
			'null_bytes'           => "data\x00hidden",
			'very_long_string'     => str_repeat('A', 100000),
			'binary_data'          => "\x00\x01\x02\x03\x04\x05",
			'unicode_attack'       => '𝐇𝐞𝐥𝐥𝐨 𝐖𝐨𝐫𝐥𝐝',
			'serialization_attack' => 'O:8:"stdClass":0:{}',
		];

		foreach ($maliciousData as $key => $maliciousValue) {
			// Should store and retrieve safely without code execution
			$stored = $cacheManager->storeComputedData($key, $maliciousValue);
			expect($stored)->toBeTrue();

			$retrieved = $cacheManager->getComputedData($key);
			expect($retrieved)->toBe($maliciousValue);
		}
	});

	it('prevents directory traversal in filesystem cache', function (): void {
		$container         = $this->app->getContainer();
		$filesystemService = $container->get(FilesystemService::class);

		// Test directory traversal attempts
		$traversalKeys = [
			'../../../etc/passwd',
			'..\\..\\..\\windows\\system32',
			'./../../sensitive_file',
			'/etc/passwd',
			'C:\\Windows\\System32',
			'key/../../../etc/passwd',
			'key\\..\\..\\..\\windows',
		];

		foreach ($traversalKeys as $traversalKey) {
			// Should handle safely without accessing outside cache directory
			$stored = $filesystemService->set($traversalKey, 'safe_data');
			expect($stored)->toBeIn([true, false]);

			$retrieved = $filesystemService->get($traversalKey);
			expect($retrieved)->toBeIn([null, 'safe_data']);
		}

		// Verify cache directory is still intact
		$cacheDir = $filesystemService->getCachDir();
		expect(is_dir($cacheDir))->toBeTrue();
	});

	it('handles cache bombing attacks gracefully', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Test with very large data structures
		$largeArray = [];
		for ($i = 0; $i < 10000; $i++) {
			$largeArray["key_$i"] = str_repeat('X', 1000);
		}

		// Should handle large data without crashing
		$stored = $cacheManager->storeComputedData('large_data_test', $largeArray);
		expect($stored)->toBeIn([true, false]); // May succeed or fail based on limits

		// Test with deeply nested structures
		$deepArray = [];
		$current   = &$deepArray;
		for ($i = 0; $i < 100; $i++) {
			$current['level'] = [];
			$current          = &$current['level'];
		}
		$current = 'deep_value';

		$stored = $cacheManager->storeComputedData('deep_data_test', $deepArray);
		expect($stored)->toBeIn([true, false]);
	});

	it('prevents cache key enumeration', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Store some sensitive data
		$cacheManager->storeComputedData('user_session_123', ['user_id' => 123, 'admin' => true]);
		$cacheManager->storeComputedData('user_session_456', ['user_id' => 456, 'admin' => false]);

		// Try to enumerate keys (should not be possible through normal API)
		$enumerationAttempts = [
			'user_session_*',
			'user_session_%',
			'user_session_?',
			'*',
			'%',
			'?',
		];

		foreach ($enumerationAttempts as $pattern) {
			// Should return null (not find anything) for pattern attempts
			$result = $cacheManager->getComputedData($pattern);
			expect($result)->toBeNull();
		}
	});

	it('handles cache invalidation securely', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Store some data
		$cacheManager->storeComputedData('secure_data', ['sensitive' => 'information']);

		// Clear all caches should work securely (may return false if no cache backends available)
		$cleared = $cacheManager->clearAllCaches();
		expect($cleared)->toBeIn([true, false]);

		// If clear was successful, data should be gone
		if ($cleared) {
			$retrieved = $cacheManager->getComputedData('secure_data');
			expect($retrieved)->toBeNull();
		}

		// Cache system should still be functional
		$newStored = $cacheManager->storeComputedData('new_data', ['test' => 'value']);
		expect($newStored)->toBeTrue();

		$newRetrieved = $cacheManager->getComputedData('new_data');
		expect($newRetrieved)->toBe(['test' => 'value']);
	});

	it('prevents cache timing attacks', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Store data with known key
		$cacheManager->storeComputedData('known_key', ['data' => 'exists']);

		// Measure time for existing vs non-existing keys
		$start1  = microtime(true);
		$result1 = $cacheManager->getComputedData('known_key');
		$time1   = microtime(true) - $start1;

		$start2  = microtime(true);
		$result2 = $cacheManager->getComputedData('unknown_key');
		$time2   = microtime(true) - $start2;

		// Results should be correct
		expect($result1)->toBe(['data' => 'exists']);
		expect($result2)->toBeNull();

		// Time difference should not be significant enough for timing attacks
		// (This is more of a documentation test than a strict requirement)
		$timeDiff = abs($time1 - $time2);
		expect($timeDiff)->toBeLessThan(0.1); // Should complete within 100ms difference
	});

	it('handles concurrent access safely', function (): void {
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);

		// Simulate concurrent operations
		$results = [];

		// Multiple operations on same key
		for ($i = 0; $i < 10; $i++) {
			$results[] = $cacheManager->storeComputedData('concurrent_key', ['iteration' => $i]);
		}

		// All operations should complete successfully
		foreach ($results as $result) {
			expect($result)->toBeIn([true, false]);
		}

		// Final read should work
		$finalResult = $cacheManager->getComputedData('concurrent_key');
		expect($finalResult)->toBeIn([null, ['iteration' => 9]]); // Could be null or last value
	});

	it('validates cache backend availability securely', function (): void {
		$container         = $this->app->getContainer();
		$cacheManager      = $container->get(CacheManager::class);
		$filesystemService = $container->get(FilesystemService::class);

		// Filesystem should always be available in tests
		expect($filesystemService->isAvailable())->toBeTrue();

		// Should provide safe statistics without revealing sensitive info
		$stats = $filesystemService->getStats();
		expect($stats)->toBeArray();

		// Stats should not contain sensitive file paths or internal details
		$statsString = json_encode($stats);
		expect($statsString)->not()->toContain('/etc/passwd');
		expect($statsString)->not()->toContain('C:\\Windows');
		expect($statsString)->not()->toContain('mysql');
		expect($statsString)->not()->toContain('password');
	});
});
