<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Support\Config;

final class RedisServiceTest extends TestCase
{
	private RedisService $redisService;
	private Config $config;

	protected function setUp(): void
	{
		$this->config = new Config([
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => '/tmp',
			'tmpdir'     => '/tmp',
			'cache'      => [
				'redis' => [
					'enabled'  => true,
					'host'     => '127.0.0.1',
					'port'     => 6379,
					'timeout'  => 1,
					'password' => null,
					'database' => 0,
				],
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
		]);

		$this->redisService = new RedisService($this->config);
	}

	public function testConstructorSetsDefaultValues(): void
	{
		$configWithoutRedis = new Config([
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => '/tmp',
			'tmpdir'     => '/tmp',
			'cache'      => [],
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
		]);

		$service = new RedisService($configWithoutRedis);

		// Should create service even without Redis config
		$this->assertInstanceOf(RedisService::class, $service);
	}

	public function testConstructorWithCustomConfiguration(): void
	{
		$customConfig = new Config([
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => '/tmp',
			'tmpdir'     => '/tmp',
			'cache'      => [
				'redis' => [
					'enabled'  => false,
					'host'     => 'redis.example.com',
					'port'     => 6380,
					'timeout'  => 5,
					'password' => 'secret',
					'database' => 1,
				],
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
		]);

		$service = new RedisService($customConfig);

		// Should create service with custom config
		$this->assertInstanceOf(RedisService::class, $service);
	}

	public function testIsInstalled(): void
	{
		$isInstalled = $this->redisService->isInstalled();

		// Should return boolean indicating if Redis extension is loaded
		$this->assertIsBool($isInstalled);

		// Should match actual extension status
		$expectedInstalled = extension_loaded('redis') && class_exists('Redis');
		$this->assertEquals($expectedInstalled, $isInstalled);
	}

	public function testIsAvailableWhenRedisNotInstalled(): void
	{
		if (extension_loaded('redis') && class_exists('Redis')) {
			$this->markTestSkipped('Redis is installed, cannot test unavailable scenario');
		}

		$available = $this->redisService->isAvailable();
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
			'cache'      => [
				'redis' => [
					'enabled' => false,
				],
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
		]);

		$service   = new RedisService($disabledConfig);
		$available = $service->isAvailable();

		$this->assertFalse($available);
	}

	public function testIsActiveWhenNotAvailable(): void
	{
		if ($this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is available, cannot test inactive scenario');
		}

		$active = $this->redisService->isActive();
		$this->assertFalse($active);
	}

	public function testGetWhenNotAvailable(): void
	{
		if ($this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is available, skipping unavailable test');
		}

		$result = $this->redisService->get('test_key');
		$this->assertNull($result);
	}

	public function testSetWhenNotAvailable(): void
	{
		if ($this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is available, skipping unavailable test');
		}

		$result = $this->redisService->set('test_key', 'test_value');
		$this->assertFalse($result);
	}

	public function testDeleteWhenNotAvailable(): void
	{
		if ($this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is available, skipping unavailable test');
		}

		$result = $this->redisService->delete('test_key');
		$this->assertFalse($result);
	}

	public function testClearWhenNotAvailable(): void
	{
		if ($this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is available, skipping unavailable test');
		}

		$result = $this->redisService->clear();
		$this->assertFalse($result);
	}

	public function testClearByPatternWhenNotAvailable(): void
	{
		if ($this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is available, skipping unavailable test');
		}

		$result = $this->redisService->clearByPattern('test_*');
		$this->assertFalse($result);
	}

	public function testGetStatsWhenNotAvailable(): void
	{
		if ($this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is available, skipping unavailable test');
		}

		$stats = $this->redisService->getStats();

		$this->assertIsArray($stats);
		$this->assertArrayHasKey('available', $stats);
		$this->assertArrayHasKey('enabled', $stats);
		$this->assertFalse($stats['available']);
	}

	public function testGetName(): void
	{
		$name = $this->redisService->getName();
		$this->assertEquals('Redis', $name);
	}

	public function testGetRecommendationsWhenNotAvailable(): void
	{
		if ($this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is available, skipping unavailable test');
		}

		$recommendations = $this->redisService->getRecommendations();

		$this->assertIsArray($recommendations);
		$this->assertNotEmpty($recommendations);
		$this->assertStringContainsString('not available', $recommendations[0]);
	}

	// Tests for when Redis IS available
	public function testBasicOperationsWhenAvailable(): void
	{
		if (!$this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is not available');
		}

		// Test set and get
		$key   = 'test_cache_key_' . uniqid();
		$value = 'test_cache_value_' . time();

		$setResult = $this->redisService->set($key, $value);
		$this->assertTrue($setResult);

		$getValue = $this->redisService->get($key);
		$this->assertEquals($value, $getValue);

		// Test delete
		$deleteResult = $this->redisService->delete($key);
		$this->assertTrue($deleteResult);

		$getAfterDelete = $this->redisService->get($key);
		$this->assertNull($getAfterDelete);
	}

	public function testSetWithTTLWhenAvailable(): void
	{
		if (!$this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is not available');
		}

		$key   = 'test_ttl_key_' . uniqid();
		$value = 'test_ttl_value';

		// Set with TTL
		$result = $this->redisService->set($key, $value, 1);
		$this->assertTrue($result);

		// Should be available immediately
		$getValue = $this->redisService->get($key);
		$this->assertEquals($value, $getValue);

		// Clean up
		$this->redisService->delete($key);
	}

	public function testSerializationWhenAvailable(): void
	{
		if (!$this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is not available');
		}

		$key = 'test_serialization_' . uniqid();

		// Test array serialization
		$arrayValue = ['foo' => 'bar', 'nested' => ['key' => 'value']];
		$this->redisService->set($key, $arrayValue);
		$retrievedArray = $this->redisService->get($key);
		$this->assertEquals($arrayValue, $retrievedArray);

		// Test object serialization
		$objectValue = (object)['prop' => 'value'];
		$this->redisService->set($key, $objectValue);
		$retrievedObject = $this->redisService->get($key);
		$this->assertEquals($objectValue, $retrievedObject);

		// Clean up
		$this->redisService->delete($key);
	}

	public function testIsActiveWhenAvailable(): void
	{
		if (!$this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is not available');
		}

		$active = $this->redisService->isActive();
		$this->assertTrue($active);
	}

	public function testGetStatsWhenAvailable(): void
	{
		if (!$this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is not available');
		}

		$stats = $this->redisService->getStats();

		$this->assertIsArray($stats);
		$this->assertArrayHasKey('available', $stats);
		$this->assertArrayHasKey('enabled', $stats);
		$this->assertArrayHasKey('host', $stats);
		$this->assertArrayHasKey('port', $stats);
		$this->assertTrue($stats['available']);
	}

	public function testGetRecommendationsWhenAvailable(): void
	{
		if (!$this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is not available');
		}

		$recommendations = $this->redisService->getRecommendations();

		$this->assertIsArray($recommendations);
		$this->assertNotEmpty($recommendations);
		$this->assertStringContainsString('available', $recommendations[0]);
	}

	public function testClearByPatternWhenAvailable(): void
	{
		if (!$this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is not available');
		}

		// Set some test keys
		$prefix = 'pattern_test_' . uniqid();
		$this->redisService->set($prefix . '_key1', 'value1');
		$this->redisService->set($prefix . '_key2', 'value2');
		$this->redisService->set('other_key', 'other_value');

		// Clear by pattern
		$result = $this->redisService->clearByPattern($prefix . '*');
		$this->assertTrue($result);

		// Pattern keys should be gone
		$this->assertNull($this->redisService->get($prefix . '_key1'));
		$this->assertNull($this->redisService->get($prefix . '_key2'));

		// Other keys should remain (if they existed)
		// Clean up
		$this->redisService->delete('other_key');
	}

	public function testDeleteNonExistentKey(): void
	{
		if (!$this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is not available');
		}

		$nonExistentKey = 'non_existent_key_' . uniqid();
		$result         = $this->redisService->delete($nonExistentKey);

		// Deleting non-existent key should return false
		$this->assertFalse($result);
	}

	public function testGetNonExistentKey(): void
	{
		if (!$this->redisService->isAvailable()) {
			$this->markTestSkipped('Redis is not available');
		}

		$nonExistentKey = 'non_existent_key_' . uniqid();
		$result         = $this->redisService->get($nonExistentKey);

		$this->assertNull($result);
	}
}
