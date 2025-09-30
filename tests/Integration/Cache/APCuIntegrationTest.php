<?php

namespace Tests\Integration\Cache;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;
use TotalCMS\Support\Config;

final class APCuIntegrationTest extends TestCase
{
	private CacheManager $cacheManager;
	private CacheReporter $cacheReporter;
	private APCuService $apcuService;
	private Config $config;

	protected function setUp(): void
	{
		$this->config = $this->createTestConfig();

		// Initialize services
		$this->apcuService = new APCuService($this->config);
		$filesystemService = new FilesystemService($this->config);
		$opcacheService    = new OPcacheService();
		$redisService      = new RedisService($this->config);
		$memcachedService  = new MemcachedService($this->config);
		$devModeManager    = new DevModeManager();

		// Create real TextWatermarkFactory instance for testing
		$mockStorage          = $this->createMock(\TotalCMS\Domain\Storage\StorageAdapterInterface::class);
		$mockLoggerFactory    = $this->createMock(\TotalCMS\Factory\LoggerFactory::class);
		$textWatermarkFactory = new TextWatermarkFactory($mockStorage, $this->config, $mockLoggerFactory);

		$mockLoggerFactoryForCache = $this->createMock(\TotalCMS\Factory\LoggerFactory::class);
		$this->cacheManager        = new CacheManager(
			$filesystemService,
			$opcacheService,
			$redisService,
			$memcachedService,
			$this->apcuService,
			$textWatermarkFactory,
			$devModeManager,
			$this->config,
			$mockLoggerFactoryForCache
		);

		$this->cacheReporter = new CacheReporter(
			$filesystemService,
			$opcacheService,
			$redisService,
			$memcachedService,
			$this->apcuService,
			$devModeManager
		);
	}

	protected function tearDown(): void
	{
		// Clean up APCu test data if available
		if ($this->apcuService->isAvailable()) {
			$this->apcuService->clear();
		}
	}

	public function testAPCuPriorityInCacheManager(): void
	{
		if (!$this->apcuService->isAvailable()) {
			$this->markTestSkipped('APCu is not available for testing');
		}

		$key   = 'priority_test_' . uniqid();
		$value = 'test_data_for_priority';

		// Store data using CacheManager
		$result = $this->cacheManager->storeData($key, $value, 60);
		$this->assertTrue($result, 'CacheManager should successfully store data');

		// Verify data is accessible through CacheManager
		$retrievedData = $this->cacheManager->getData($key);
		$this->assertEquals($value, $retrievedData, 'CacheManager should retrieve the correct data');

		// Verify data is actually stored in APCu (not just filesystem fallback)
		$directAPCuData = $this->apcuService->get($key);
		$this->assertEquals($value, $directAPCuData, 'Data should be stored directly in APCu');
	}

	public function testCollectionIndexCaching(): void
	{
		if (!$this->apcuService->isAvailable()) {
			$this->markTestSkipped('APCu is not available for testing');
		}

		$collectionName = 'test_collection_' . uniqid();
		$indexData      = [
			'objects'      => ['obj1', 'obj2', 'obj3'],
			'count'        => 3,
			'lastModified' => time(),
		];

		// Store collection index
		$result = $this->cacheManager->storeCollectionIndex($collectionName, $indexData);
		$this->assertTrue($result, 'Should successfully store collection index');

		// Retrieve collection index
		$retrievedIndex = $this->cacheManager->getCollectionIndex($collectionName);
		$this->assertEquals($indexData, $retrievedIndex, 'Should retrieve correct collection index');

		// Test cache clearing
		$clearResult = $this->cacheManager->clearCollectionIndex($collectionName);
		$this->assertTrue($clearResult, 'Should successfully clear collection index');

		$clearedIndex = $this->cacheManager->getCollectionIndex($collectionName);
		$this->assertNull($clearedIndex, 'Index should be null after clearing');
	}

	public function testApiResponseCaching(): void
	{
		if (!$this->apcuService->isAvailable()) {
			$this->markTestSkipped('APCu is not available for testing');
		}

		$endpoint     = '/api/test/endpoint';
		$params       = ['param1' => 'value1', 'param2' => 'value2'];
		$responseData = ['status' => 'success', 'data' => ['items' => [1, 2, 3]]];

		// Store API response
		$result = $this->cacheManager->storeApiResponse($endpoint, $params, $responseData);
		$this->assertTrue($result, 'Should successfully store API response');

		// Retrieve API response
		$retrievedResponse = $this->cacheManager->getApiResponse($endpoint, $params);
		$this->assertEquals($responseData, $retrievedResponse, 'Should retrieve correct API response');

		// Test with different params (should return null)
		$differentParams   = ['param1' => 'different_value'];
		$differentResponse = $this->cacheManager->getApiResponse($endpoint, $differentParams);
		$this->assertNull($differentResponse, 'Should return null for different parameters');
	}

	public function testComputedDataCaching(): void
	{
		if (!$this->apcuService->isAvailable()) {
			$this->markTestSkipped('APCu is not available for testing');
		}

		$cacheKey     = 'computed_test_' . uniqid();
		$computedData = [
			'expensive_calculation' => 'result_' . time(),
			'complex_data'          => ['nested' => ['structure' => 'value']],
		];

		// Store computed data
		$result = $this->cacheManager->storeComputedData($cacheKey, $computedData);
		$this->assertTrue($result, 'Should successfully store computed data');

		// Retrieve computed data
		$retrievedData = $this->cacheManager->getComputedData($cacheKey);
		$this->assertEquals($computedData, $retrievedData, 'Should retrieve correct computed data');

		// Clear specific computed data
		$clearResult = $this->cacheManager->clearComputedData($cacheKey);
		$this->assertTrue($clearResult, 'Should successfully clear computed data');

		$clearedData = $this->cacheManager->getComputedData($cacheKey);
		$this->assertNull($clearedData, 'Should return null after clearing');
	}

	public function testCacheTypeClearingWithAPCu(): void
	{
		if (!$this->apcuService->isAvailable()) {
			$this->markTestSkipped('APCu is not available for testing');
		}

		// Use the appropriate cache manager methods to test type clearing
		// These methods handle domain prefixing internally

		// Store collection data
		$this->assertTrue($this->cacheManager->storeCollectionIndex('blog', ['data' => 'blog_data'], 60));
		$this->assertTrue($this->cacheManager->storeCollectionIndex('news', ['data' => 'news_data'], 60));

		// Store API response data
		$this->assertTrue($this->cacheManager->storeApiResponse('endpoint1', [], 'api_data', 60));

		// Store computed data
		$this->assertTrue($this->cacheManager->storeComputedData('schema', 'schema_data', 60));

		// Verify data is stored
		$this->assertNotNull($this->cacheManager->getCollectionIndex('blog'));
		$this->assertNotNull($this->cacheManager->getCollectionIndex('news'));
		$this->assertNotNull($this->cacheManager->getApiResponse('endpoint1', []));
		$this->assertNotNull($this->cacheManager->getComputedData('schema'));

		// Clear collection cache type
		$result = $this->cacheManager->clearByType(CacheManager::PREFIX_COLLECTION);
		$this->assertTrue($result, 'Should successfully clear collection cache type');

		// Verify collection data is cleared but others remain
		$this->assertNull($this->cacheManager->getCollectionIndex('blog'));
		$this->assertNull($this->cacheManager->getCollectionIndex('news'));

		// API and computed data should remain
		$this->assertNotNull($this->cacheManager->getApiResponse('endpoint1', []));
		$this->assertNotNull($this->cacheManager->getComputedData('schema'));
	}

	public function testCacheReporterWithAPCu(): void
	{
		$stats = $this->cacheReporter->getCacheStats();

		$this->assertIsArray($stats, 'Cache stats should return an array');
		$this->assertArrayHasKey('available_backends', $stats);
		$this->assertArrayHasKey('backend_status', $stats);

		// Check if APCu is properly reported
		$backends = $stats['available_backends'];
		$this->assertArrayHasKey('apcu', $backends, 'APCu should be listed in available backends');
		$this->assertEquals('APCu', $backends['apcu'], 'APCu should have correct display name');

		// Check APCu status
		$backendStatus = $stats['backend_status'];
		$this->assertArrayHasKey('apcu', $backendStatus, 'APCu status should be reported');

		if ($this->apcuService->isAvailable()) {
			$this->assertEquals('active', $backendStatus['apcu'], 'APCu should show as active when available');

			// Check APCu service stats
			$this->assertArrayHasKey('services', $stats);
			if (isset($stats['services']['apcu'])) {
				$apcuStats = $stats['services']['apcu'];
				$this->assertTrue($apcuStats['available'], 'APCu service stats should show as available');
				$this->assertArrayHasKey('hit_rate', $apcuStats);
				$this->assertArrayHasKey('prefix', $apcuStats);
			}
		}
	}

	public function testUsageStatsIncludeAPCu(): void
	{
		$usageStats = $this->cacheReporter->getUsageStats();

		$this->assertIsArray($usageStats, 'Usage stats should return an array');
		$this->assertArrayHasKey('apcu_available', $usageStats, 'Should report APCu availability');
		$this->assertEquals($this->apcuService->isAvailable(), $usageStats['apcu_available']);

		$this->assertArrayHasKey('preferred_backend', $usageStats);

		// If APCu is available, it should be the preferred backend
		if ($this->apcuService->isAvailable()) {
			$this->assertEquals('apcu', $usageStats['preferred_backend'], 'APCu should be preferred backend when available');
		}
	}

	public function testClearAllCachesIncludesAPCu(): void
	{
		if (!$this->apcuService->isAvailable()) {
			$this->markTestSkipped('APCu is not available for testing');
		}

		// Store test data
		$testKey = 'clear_all_test_' . uniqid();
		$this->cacheManager->storeData($testKey, 'test_data', 60);

		// Verify data is stored
		$this->assertNotNull($this->cacheManager->getData($testKey));

		// Clear all caches
		$result = $this->cacheManager->clearAllCaches();
		$this->assertTrue($result, 'Should successfully clear all caches');

		// Note: clearAllCaches() might clear all of APCu, not just our prefixed data
		// This is expected behavior for the "clear all" operation
	}

	private function recursiveRemoveDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) {
			$path = $dir . DIRECTORY_SEPARATOR . $file;
			is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
		}
		rmdir($dir);
	}

	private function createTestConfig(): Config
	{
		$settings = [
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => '/tmp',
			'tmpdir'     => '/tmp',
			'cache'      => [
				'apcu' => [
					'enabled' => true,
					'prefix'  => 'test_integration_',
				],
				'redis'     => ['enabled' => false], // Disable Redis for isolated APCu testing
				'memcached' => ['enabled' => false], // Disable Memcached for isolated APCu testing
			],
			'logger'     => [],
			'sentry'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'htmlclean'  => [],
			'timezone'   => 'UTC',
			'imageworks' => [],
		];

		return new Config($settings);
	}
}
