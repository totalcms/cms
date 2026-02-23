<?php

namespace Tests\Unit\Domain\Cache;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\ImageWorks\Service\WatermarkCleanupService;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

final class CacheManagerVersionCheckTest extends TestCase
{
	private string $testCacheDir;
	private string $appVersionFile;

	private \PHPUnit\Framework\MockObject\MockObject $filesystemService;
	private \PHPUnit\Framework\MockObject\MockObject $opcacheService;
	private \PHPUnit\Framework\MockObject\MockObject $redisService;
	private \PHPUnit\Framework\MockObject\MockObject $memcachedService;
	private \PHPUnit\Framework\MockObject\MockObject $apcuService;
	private \PHPUnit\Framework\MockObject\MockObject $watermarkCleanupService;
	private \PHPUnit\Framework\MockObject\MockObject $devModeManager;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private \PHPUnit\Framework\MockObject\MockObject $loggerFactory;

	protected function setUp(): void
	{
		$this->testCacheDir   = sys_get_temp_dir() . '/totalcms-test-cache-' . uniqid();
		$this->appVersionFile = $this->testCacheDir . '/.app_version';
		mkdir($this->testCacheDir, 0777, true);

		$this->filesystemService       = $this->createMock(FilesystemService::class);
		$this->opcacheService          = $this->createMock(OPcacheService::class);
		$this->redisService            = $this->createMock(RedisService::class);
		$this->memcachedService        = $this->createMock(MemcachedService::class);
		$this->apcuService             = $this->createMock(APCuService::class);
		$this->watermarkCleanupService = $this->createMock(WatermarkCleanupService::class);
		$this->devModeManager          = $this->createMock(DevModeManager::class);
		$this->config                  = $this->createMock(Config::class);
		$this->loggerFactory           = $this->createMock(LoggerFactory::class);

		$this->config->domain = 'test.example.com';

		$this->filesystemService->method('getCachDir')->willReturn($this->testCacheDir);
		$this->filesystemService->method('isAvailable')->willReturn(true);
		$this->filesystemService->method('isInstalled')->willReturn(true);

		// All other cache services unavailable for simplicity
		$this->opcacheService->method('isAvailable')->willReturn(false);
		$this->redisService->method('isAvailable')->willReturn(false);
		$this->memcachedService->method('isAvailable')->willReturn(false);
		$this->apcuService->method('isAvailable')->willReturn(false);

		$this->loggerFactory->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->method('createLogger')->willReturn(
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);
	}

	protected function tearDown(): void
	{
		if (is_dir($this->testCacheDir)) {
			$files = array_diff((array)scandir($this->testCacheDir), ['.', '..']);
			foreach ($files as $file) {
				unlink($this->testCacheDir . '/' . $file);
			}
			rmdir($this->testCacheDir);
		}
	}

	private function createCacheManager(): CacheManager
	{
		return new CacheManager(
			$this->filesystemService,
			$this->opcacheService,
			$this->redisService,
			$this->memcachedService,
			$this->apcuService,
			$this->watermarkCleanupService,
			$this->devModeManager,
			$this->config,
			$this->loggerFactory,
		);
	}

	public function testReturnsFalseWhenVersionHasNotChanged(): void
	{
		file_put_contents($this->appVersionFile, Version::get());

		$manager = $this->createCacheManager();
		$result  = $manager->clearIfVersionChanged();

		$this->assertFalse($result);
	}

	public function testReturnsTrueAndClearsCachesWhenVersionChanged(): void
	{
		file_put_contents($this->appVersionFile, 'old-version-1.0.0');

		$this->filesystemService->expects($this->once())
			->method('clear')
			->willReturn(true);

		$manager = $this->createCacheManager();
		$result  = $manager->clearIfVersionChanged();

		$this->assertTrue($result);
		$this->assertSame(Version::get(), trim((string)file_get_contents($this->appVersionFile)));
	}

	public function testReturnsTrueWhenNoVersionFileExists(): void
	{
		$this->assertFileDoesNotExist($this->appVersionFile);

		$this->filesystemService->expects($this->once())
			->method('clear')
			->willReturn(true);

		$manager = $this->createCacheManager();
		$result  = $manager->clearIfVersionChanged();

		$this->assertTrue($result);
		$this->assertFileExists($this->appVersionFile);
		$this->assertSame(Version::get(), trim((string)file_get_contents($this->appVersionFile)));
	}

	public function testReturnsFalseWhenFilesystemNotAvailable(): void
	{
		$this->filesystemService = $this->createMock(FilesystemService::class);
		$this->filesystemService->method('getCachDir')->willReturn($this->testCacheDir);
		$this->filesystemService->method('isAvailable')->willReturn(false);
		$this->filesystemService->method('isInstalled')->willReturn(false);

		$this->filesystemService->expects($this->never())
			->method('clear');

		$manager = $this->createCacheManager();
		$result  = $manager->clearIfVersionChanged();

		$this->assertFalse($result);
	}

	public function testWritesCurrentVersionAfterClear(): void
	{
		file_put_contents($this->appVersionFile, 'different-version');

		$this->filesystemService->expects($this->once())
			->method('clear')
			->willReturn(true);

		$manager = $this->createCacheManager();
		$manager->clearIfVersionChanged();

		$storedVersion = trim((string)file_get_contents($this->appVersionFile));
		$this->assertSame(Version::get(), $storedVersion);
	}

	public function testDoesNotClearWhenVersionMatches(): void
	{
		file_put_contents($this->appVersionFile, Version::get());

		$this->filesystemService->expects($this->never())
			->method('clear');

		$manager = $this->createCacheManager();
		$manager->clearIfVersionChanged();
	}
}
