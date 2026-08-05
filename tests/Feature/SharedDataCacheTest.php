<?php

declare(strict_types=1);

namespace Tests\Feature;

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
 * Two installs, two domains, one tcms-data — the deployment this feature exists
 * for. With domain scoping off, a save through one install must invalidate the
 * other; with it on, they stay isolated exactly as before.
 */
final class SharedDataCacheTest extends TestCase
{
	private string $root = '';

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/tcms-shared-' . uniqid();
		mkdir($this->root . '/site-a/cache', 0755, true);
		mkdir($this->root . '/site-b/cache', 0755, true);
		mkdir($this->root . '/tcms-data/.system', 0755, true);
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	private function makeManager(string $site, string $domain, bool $domainScoped): CacheManager
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();

		$config->domain   = $domain;
		$config->datadir  = $this->root . '/tcms-data';
		$config->cachedir = $this->root . '/' . $site . '/cache';
		$config->cache    = ['filesystem' => true, 'domainScoped' => $domainScoped];

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')
			->willReturn($this->createMock(\Psr\Log\LoggerInterface::class));

		// Filesystem is the only live backend: it is always present, so the test
		// behaves the same whether or not APCu/Redis exist on the test machine.
		$apcu      = $this->createMock(APCuService::class);
		$redis     = $this->createMock(RedisService::class);
		$memcached = $this->createMock(MemcachedService::class);
		$apcu->method('isAvailable')->willReturn(false);
		$redis->method('isAvailable')->willReturn(false);
		$memcached->method('isAvailable')->willReturn(false);

		return new CacheManager(
			new FilesystemService($config),
			$this->createMock(OPcacheService::class),
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

	public function testSharedModeLetsOneInstallSeeAnothersCachedIndex(): void
	{
		$a = $this->makeManager('site-a', 'a.example.com', false);
		$b = $this->makeManager('site-b', 'b.example.com', false);

		$a->storeCollectionIndex('blog', ['count' => 3]);

		$this->assertSame(['count' => 3], $b->getCollectionIndex('blog'));
	}

	public function testSharedModeInvalidatesEveryInstallOnSave(): void
	{
		$a = $this->makeManager('site-a', 'a.example.com', false);
		$b = $this->makeManager('site-b', 'b.example.com', false);

		$a->storeCollectionIndex('blog', ['count' => 3]);
		$b->getCollectionIndex('blog');

		$a->clearCollectionIndex('blog');

		$this->assertNull($b->getCollectionIndex('blog'));
	}

	public function testVersionStampStaysPerInstallInSharedMode(): void
	{
		// The version stamp describes this install's code, not the shared
		// content. Written into the shared entry directory, two installs on
		// different T3 versions would each see the other's stamp, clear all
		// caches, and thrash on every request.
		$a = $this->makeManager('site-a', 'a.example.com', false);

		$a->clearIfVersionChanged();

		$this->assertFileExists($this->root . '/site-a/cache/.app_version');
		$this->assertFileDoesNotExist($this->root . '/tcms-data/.system/cache/.app_version');
	}

	public function testScopedModeKeepsInstallsIsolated(): void
	{
		$a = $this->makeManager('site-a', 'a.example.com', true);
		$b = $this->makeManager('site-b', 'b.example.com', true);

		$a->storeCollectionIndex('blog', ['count' => 3]);

		$this->assertNull($b->getCollectionIndex('blog'));
	}
}
