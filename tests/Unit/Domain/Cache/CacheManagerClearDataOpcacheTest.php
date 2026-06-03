<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cache;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\CacheInvalidationSignal;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\ImageWorks\Service\WatermarkCleanupService;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Regression: deleting a data key must NOT reset the pool-wide OPcache.
 * OPcache caches compiled PHP bytecode, not this key/value data, and
 * opcache_reset() is global — flushing it per data-key delete collapsed the
 * bytecode cache for every co-located site on a shared FPM pool.
 */
final class CacheManagerClearDataOpcacheTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $opcacheService;
	private \PHPUnit\Framework\MockObject\MockObject $filesystemService;
	private CacheManager $cacheManager;

	protected function setUp(): void
	{
		$this->filesystemService = $this->createMock(FilesystemService::class);
		$this->opcacheService    = $this->createMock(OPcacheService::class);
		$redis                   = $this->createMock(RedisService::class);
		$memcached               = $this->createMock(MemcachedService::class);
		$apcu                    = $this->createMock(APCuService::class);
		$config                  = $this->createMock(Config::class);
		$loggerFactory           = $this->createMock(LoggerFactory::class);

		$config->domain = 'test.example.com';

		// OPcache IS available — yet clearData() must still not call clear().
		$this->opcacheService->method('isAvailable')->willReturn(true);
		$this->filesystemService->method('isAvailable')->willReturn(true);
		$this->filesystemService->method('delete')->willReturn(true);
		$redis->method('isAvailable')->willReturn(false);
		$memcached->method('isAvailable')->willReturn(false);
		$apcu->method('isAvailable')->willReturn(false);

		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->createMock(\Psr\Log\LoggerInterface::class));

		$this->cacheManager = new CacheManager(
			$this->filesystemService,
			$this->opcacheService,
			$redis,
			$memcached,
			$apcu,
			$this->createMock(WatermarkCleanupService::class),
			$this->createMock(DevModeManager::class),
			$this->createMock(CacheInvalidationSignal::class),
			new EventDispatcher($this->createMock(\Psr\Log\LoggerInterface::class)),
			$config,
			$loggerFactory,
		);
	}

	public function testClearDataDoesNotResetOpcache(): void
	{
		$this->opcacheService->expects($this->never())->method('clear');

		$this->cacheManager->clearData('some:data:key');
	}
}
