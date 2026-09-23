<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cache\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\CacheBackends;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\RedisService;

/**
 * The four key/value backends in priority order, and the fan-out primitives
 * every CacheManager read/write/delete is built from.
 */
final class CacheBackendsTest extends TestCase
{
	private APCuService&MockObject $apcu;
	private RedisService&MockObject $redis;
	private MemcachedService&MockObject $memcached;
	private FilesystemService&MockObject $filesystem;

	protected function setUp(): void
	{
		$this->apcu       = $this->createMock(APCuService::class);
		$this->redis      = $this->createMock(RedisService::class);
		$this->memcached  = $this->createMock(MemcachedService::class);
		$this->filesystem = $this->createMock(FilesystemService::class);
	}

	private function backends(): CacheBackends
	{
		return new CacheBackends($this->apcu, $this->redis, $this->memcached, $this->filesystem);
	}

	public function testMemoryBackendsComeInPriorityOrderAndHonorTheConfig(): void
	{
		$this->apcu->method('isAvailable')->willReturn(true);
		$this->redis->method('isAvailable')->willReturn(false);
		$this->memcached->method('isAvailable')->willReturn(true);

		$this->assertSame([$this->apcu, $this->memcached], $this->backends()->memory());
	}

	public function testInstalledMemoryBackendsBypassTheConfig(): void
	{
		$this->apcu->method('isAvailable')->willReturn(false);
		$this->apcu->method('isInstalled')->willReturn(true);
		$this->redis->method('isInstalled')->willReturn(true);
		$this->memcached->method('isInstalled')->willReturn(false);

		$this->assertSame([$this->apcu, $this->redis], $this->backends()->memory(installed: true));
	}

	public function testAvailableAppendsTheFilesystemLast(): void
	{
		$this->redis->method('isAvailable')->willReturn(true);
		$this->filesystem->method('isAvailable')->willReturn(true);

		$this->assertSame([$this->redis, $this->filesystem], $this->backends()->available());
	}

	public function testNetworkPrefersRedisOverMemcached(): void
	{
		$this->redis->method('isAvailable')->willReturn(true);
		$this->memcached->method('isAvailable')->willReturn(true);

		$this->assertSame($this->redis, $this->backends()->network());
	}

	public function testNetworkFallsBackToMemcachedThenNothing(): void
	{
		$this->memcached->method('isAvailable')->willReturn(true);
		$this->assertSame($this->memcached, $this->backends()->network());

		$this->memcached = $this->createMock(MemcachedService::class);
		$this->assertNull($this->backends()->network());
	}

	public function testFirstHitStopsAtTheFirstBackendThatAnswers(): void
	{
		$this->apcu->method('get')->with('k')->willReturn(null);
		$this->redis->method('get')->with('k')->willReturn(['hit' => true]);
		$this->memcached->expects($this->never())->method('get');

		$hit = $this->backends()->firstHit([$this->apcu, $this->redis, $this->memcached], 'k');

		$this->assertSame(['hit' => true], $hit);
	}

	public function testFirstHitIsNullWhenNobodyAnswers(): void
	{
		$this->assertNull($this->backends()->firstHit([$this->apcu, $this->redis], 'k'));
	}

	public function testStoreFirstStopsAtTheFirstBackendThatAcceptsTheWrite(): void
	{
		$this->apcu->method('set')->with('k', 'v', 60)->willReturn(false);
		$this->redis->method('set')->with('k', 'v', 60)->willReturn(true);
		$this->memcached->expects($this->never())->method('set');

		$this->assertTrue($this->backends()->storeFirst([$this->apcu, $this->redis, $this->memcached], 'k', 'v', 60));
	}

	public function testStoreFirstIsFalseWhenNobodyAcceptsIt(): void
	{
		$this->assertFalse($this->backends()->storeFirst([], 'k', 'v', 60));
	}

	public function testDeleteFromHitsEveryBackendAndReportsAnyFailure(): void
	{
		$this->apcu->expects($this->once())->method('delete')->with('k')->willReturn(false);
		$this->redis->expects($this->once())->method('delete')->with('k')->willReturn(true);
		$this->filesystem->expects($this->once())->method('delete')->with('k')->willReturn(true);

		$this->assertFalse($this->backends()->deleteFrom([$this->apcu, $this->redis, $this->filesystem], 'k'));
	}

	public function testDeleteFromNothingSucceeds(): void
	{
		$this->assertTrue($this->backends()->deleteFrom([], 'k'));
	}

	public function testClearPatternHitsEveryBackendAndReportsAnyFailure(): void
	{
		$this->apcu->expects($this->once())->method('clearByPattern')->with('p:api:*')->willReturn(true);
		$this->filesystem->expects($this->once())->method('clearByPattern')->with('p:api:*')->willReturn(false);

		$this->assertFalse($this->backends()->clearPattern([$this->apcu, $this->filesystem], 'p:api:*'));
	}
}
