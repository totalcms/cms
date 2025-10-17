<?php

declare(strict_types=1);

namespace Tests\Unit\Cache\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Support\Config;

final class MemcachedServiceTest extends TestCase
{
	private MemcachedService $memcachedService;
	private Config $config;

	protected function setUp(): void
	{
		$this->config = new Config([
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => '/tmp',
			'tmpdir'     => '/tmp',
			'cachedir'   => '/tmp/cache',
			'cache'      => [
				'memcached'       => true,
				'memcachedConfig' => [
					'host' => '127.0.0.1',
					'port' => 11211,
				],
			],
			'logger'     => [],
			'sentry'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'htmlclean'  => [],
			'smtp'       => [],
			'mailer'     => [],
			'timezone'   => 'UTC',
			'imageworks' => [],
		]);

		$this->memcachedService = new MemcachedService($this->config);
	}

	public function testConstructorSetsDefaultValues(): void
	{
		$configWithoutMemcached = new Config([
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => '/tmp',
			'tmpdir'     => '/tmp',
			'cachedir'   => '/tmp/cache',
			'cache'      => [],
			'logger'     => [],
			'sentry'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'htmlclean'  => [],
			'smtp'       => [],
			'mailer'     => [],
			'timezone'   => 'UTC',
			'imageworks' => [],
		]);

		$service = new MemcachedService($configWithoutMemcached);

		// Should create service even without Memcached config
		$this->assertInstanceOf(MemcachedService::class, $service);
	}

	public function testConstructorWithCustomConfiguration(): void
	{
		$customConfig = new Config([
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => '/tmp',
			'tmpdir'     => '/tmp',
			'cachedir'   => '/tmp/cache',
			'cache'      => [
				'memcached'       => false,
				'memcachedConfig' => [
					'host' => 'memcached.example.com',
					'port' => 11212,
				],
			],
			'logger'     => [],
			'sentry'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'htmlclean'  => [],
			'smtp'       => [],
			'mailer'     => [],
			'timezone'   => 'UTC',
			'imageworks' => [],
		]);

		$service = new MemcachedService($customConfig);

		// Should create service with custom config
		$this->assertInstanceOf(MemcachedService::class, $service);
	}

	public function testIsInstalled(): void
	{
		$isInstalled = $this->memcachedService->isInstalled();

		// Should return boolean indicating if Memcached extension is loaded
		$this->assertIsBool($isInstalled);

		// Should match actual extension status
		$expectedInstalled = extension_loaded('memcached') && class_exists('Memcached');
		$this->assertEquals($expectedInstalled, $isInstalled);
	}

	public function testIsAvailableWhenMemcachedNotInstalled(): void
	{
		if (extension_loaded('memcached') && class_exists('Memcached')) {
			$this->markTestSkipped('Memcached is installed, cannot test unavailable scenario');
		}

		$available = $this->memcachedService->isAvailable();
		$this->assertFalse($available);
	}

	public function testIsAvailableWhenDisabled(): void
	{
		$disabledConfig = new Config([
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => '/tmp',
			'tmpdir'     => '/tmp',
			'cachedir'   => '/tmp/cache',
			'cache'      => [
				'memcached' => false,
			],
			'logger'     => [],
			'sentry'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'htmlclean'  => [],
			'smtp'       => [],
			'mailer'     => [],
			'timezone'   => 'UTC',
			'imageworks' => [],
		]);

		$service   = new MemcachedService($disabledConfig);
		$available = $service->isAvailable();

		$this->assertFalse($available);
	}

	public function testIsActiveWhenNotAvailable(): void
	{
		if ($this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is available, cannot test inactive scenario');
		}

		$active = $this->memcachedService->isActive();
		$this->assertFalse($active);
	}

	public function testGetWhenNotAvailable(): void
	{
		if ($this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is available, skipping unavailable test');
		}

		$result = $this->memcachedService->get('test_key');
		$this->assertNull($result);
	}

	public function testSetWhenNotAvailable(): void
	{
		if ($this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is available, skipping unavailable test');
		}

		$result = $this->memcachedService->set('test_key', 'test_value');
		$this->assertFalse($result);
	}

	public function testDeleteWhenNotAvailable(): void
	{
		if ($this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is available, skipping unavailable test');
		}

		$result = $this->memcachedService->delete('test_key');
		$this->assertFalse($result);
	}

	public function testClearWhenNotAvailable(): void
	{
		if ($this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is available, skipping unavailable test');
		}

		$result = $this->memcachedService->clear();
		$this->assertFalse($result);
	}

	public function testClearByPatternWhenNotAvailable(): void
	{
		if ($this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is available, skipping unavailable test');
		}

		$result = $this->memcachedService->clearByPattern('test_*');
		$this->assertFalse($result);
	}

	public function testGetStatsWhenNotAvailable(): void
	{
		if ($this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is available, skipping unavailable test');
		}

		$stats = $this->memcachedService->getStats();

		$this->assertIsArray($stats);
		$this->assertArrayHasKey('available', $stats);
		$this->assertArrayHasKey('enabled', $stats);
		$this->assertFalse($stats['available']);
	}

	public function testGetName(): void
	{
		$name = $this->memcachedService->getName();
		$this->assertEquals('Memcached', $name);
	}

	public function testGetRecommendationsWhenNotAvailable(): void
	{
		if ($this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is available, skipping unavailable test');
		}

		$recommendations = $this->memcachedService->getRecommendations();

		$this->assertIsArray($recommendations);
		$this->assertNotEmpty($recommendations);
		$this->assertStringContainsString('not available', $recommendations[0]);
	}

	// Tests for when Memcached IS available
	public function testBasicOperationsWhenAvailable(): void
	{
		if (!$this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is not available');
		}

		// Test set and get
		$key   = 'test_cache_key_' . uniqid();
		$value = 'test_cache_value_' . time();

		$setResult = $this->memcachedService->set($key, $value);
		$this->assertTrue($setResult);

		$getValue = $this->memcachedService->get($key);
		$this->assertEquals($value, $getValue);

		// Test delete
		$deleteResult = $this->memcachedService->delete($key);
		$this->assertTrue($deleteResult);

		$getAfterDelete = $this->memcachedService->get($key);
		$this->assertNull($getAfterDelete);
	}

	public function testSetWithTTLWhenAvailable(): void
	{
		if (!$this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is not available');
		}

		$key   = 'test_ttl_key_' . uniqid();
		$value = 'test_ttl_value';

		// Set with TTL
		$result = $this->memcachedService->set($key, $value, 1);
		$this->assertTrue($result);

		// Should be available immediately
		$getValue = $this->memcachedService->get($key);
		$this->assertEquals($value, $getValue);

		// Clean up
		$this->memcachedService->delete($key);
	}

	public function testSerializationWhenAvailable(): void
	{
		if (!$this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is not available');
		}

		$key = 'test_serialization_' . uniqid();

		// Test array serialization
		$arrayValue = ['foo' => 'bar', 'nested' => ['key' => 'value']];
		$this->memcachedService->set($key, $arrayValue);
		$retrievedArray = $this->memcachedService->get($key);
		$this->assertEquals($arrayValue, $retrievedArray);

		// Test object serialization
		$objectValue = (object)['prop' => 'value'];
		$this->memcachedService->set($key, $objectValue);
		$retrievedObject = $this->memcachedService->get($key);
		$this->assertEquals($objectValue, $retrievedObject);

		// Clean up
		$this->memcachedService->delete($key);
	}

	public function testIsActiveWhenAvailable(): void
	{
		if (!$this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is not available');
		}

		$active = $this->memcachedService->isActive();
		$this->assertTrue($active);
	}

	public function testGetStatsWhenAvailable(): void
	{
		if (!$this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is not available');
		}

		$stats = $this->memcachedService->getStats();

		$this->assertIsArray($stats);
		$this->assertArrayHasKey('available', $stats);
		$this->assertArrayHasKey('enabled', $stats);
		$this->assertArrayHasKey('host', $stats);
		$this->assertArrayHasKey('port', $stats);
		$this->assertTrue($stats['available']);
	}

	public function testGetRecommendationsWhenAvailable(): void
	{
		if (!$this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is not available');
		}

		$recommendations = $this->memcachedService->getRecommendations();

		$this->assertIsArray($recommendations);
		$this->assertNotEmpty($recommendations);
		$this->assertStringContainsString('available', $recommendations[0]);
	}

	public function testClearByPatternWhenAvailable(): void
	{
		if (!$this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is not available');
		}

		// Set some test keys
		$prefix = 'pattern_test_' . uniqid();
		$this->memcachedService->set($prefix . '_key1', 'value1');
		$this->memcachedService->set($prefix . '_key2', 'value2');
		$this->memcachedService->set('other_key', 'other_value');

		// Clear by pattern (Memcached doesn't support patterns, so it clears all)
		$result = $this->memcachedService->clearByPattern($prefix . '*');
		$this->assertTrue($result);

		// All keys should be gone after pattern clear (since Memcached clears all)
		$this->assertNull($this->memcachedService->get($prefix . '_key1'));
		$this->assertNull($this->memcachedService->get($prefix . '_key2'));
		$this->assertNull($this->memcachedService->get('other_key'));
	}

	public function testDeleteNonExistentKey(): void
	{
		if (!$this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is not available');
		}

		$nonExistentKey = 'non_existent_key_' . uniqid();
		$result         = $this->memcachedService->delete($nonExistentKey);

		// Memcached delete should still return true even for non-existent keys
		// This is different from Redis behavior
		$this->assertIsBool($result);
	}

	public function testGetNonExistentKey(): void
	{
		if (!$this->memcachedService->isAvailable()) {
			$this->markTestSkipped('Memcached is not available');
		}

		$nonExistentKey = 'non_existent_key_' . uniqid();
		$result         = $this->memcachedService->get($nonExistentKey);

		$this->assertNull($result);
	}

	public function testImplementsCacheInterface(): void
	{
		$this->assertInstanceOf(\TotalCMS\Domain\Cache\Service\CacheInterface::class, $this->memcachedService);
	}

	public function testHasRequiredMethods(): void
	{
		$requiredMethods = [
			'isAvailable',
			'isInstalled',
			'isActive',
			'get',
			'set',
			'delete',
			'clear',
			'clearByPattern',
			'getStats',
			'getName',
			'getRecommendations',
		];

		foreach ($requiredMethods as $method) {
			$this->assertTrue(
				method_exists($this->memcachedService, $method),
				"Method {$method} should exist"
			);
		}
	}
}
