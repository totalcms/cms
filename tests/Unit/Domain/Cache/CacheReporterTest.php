<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cache;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Support\Config;

final class CacheReporterTest extends TestCase
{
	/** @param array<string,mixed> $cacheSettings */
	private function makeReporter(array $cacheSettings): CacheReporter
	{
		$config        = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->cache = $cacheSettings;

		return new CacheReporter(
			$this->createMock(FilesystemService::class),
			$this->createMock(OPcacheService::class),
			$this->createMock(RedisService::class),
			$this->createMock(MemcachedService::class),
			$this->createMock(APCuService::class),
			$this->createMock(DevModeManager::class),
			$config,
		);
	}

	public function testAllBackendsListedWithDefaultSettings(): void
	{
		$stats = $this->makeReporter([])->getCacheStats();

		$this->assertSame(
			['opcache', 'apcu', 'redis', 'memcached', 'filesystem'],
			array_keys($stats['available_backends']),
		);
		$this->assertSame(
			['opcache', 'apcu', 'redis', 'memcached', 'filesystem'],
			array_keys($stats['backend_status']),
		);
	}

	public function testExplicitlyDisabledBackendIsOmitted(): void
	{
		$stats = $this->makeReporter(['memcached' => false])->getCacheStats();

		$this->assertArrayNotHasKey('memcached', $stats['available_backends']);
		$this->assertArrayNotHasKey('memcached', $stats['backend_status']);
		$this->assertArrayNotHasKey('memcached', $stats['services']);
		$this->assertArrayHasKey('redis', $stats['available_backends']);
	}

	public function testMultipleDisabledBackendsAreOmitted(): void
	{
		$stats = $this->makeReporter(['memcached' => false, 'redis' => false, 'apcu' => false])->getCacheStats();

		$this->assertSame(
			['opcache', 'filesystem'],
			array_keys($stats['available_backends']),
		);
	}

	public function testOpcacheHasNoConfigToggleAndAlwaysShows(): void
	{
		// 'opcache' is not a cache-settings key; even a stray falsy value must
		// not hide it — it is a PHP-level feature, not a T3 cache backend toggle.
		$stats = $this->makeReporter(['opcache' => false])->getCacheStats();

		$this->assertArrayHasKey('opcache', $stats['available_backends']);
	}
}
