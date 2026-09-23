<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cache\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\CacheBackends;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\IdentityCache;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\License\Data\LicenseData;

/**
 * Per-domain identity data: the license verdict and password reset tokens.
 * The license is cached whatever the operator's cache config says (installed
 * backends + a mandatory filesystem copy); reset tokens use the configured
 * backends but a single layer.
 */
final class IdentityCacheTest extends TestCase
{
	private const PREFIX = 'abc123';

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

	private function cache(): IdentityCache
	{
		return new IdentityCache(
			new CacheBackends($this->apcu, $this->redis, $this->memcached, $this->filesystem),
			self::PREFIX,
		);
	}

	private function license(): LicenseData
	{
		return LicenseData::fromArray(['valid' => true, 'domain' => 'a.example.com', 'edition' => 'pro']);
	}

	public function testLicenseIsStoredAsAnArrayInTheFirstInstalledMemoryBackendAndAlwaysOnDisk(): void
	{
		$key = self::PREFIX . ':license';

		// APCu is installed but disabled in config: installed wins for the license.
		$this->apcu->method('isAvailable')->willReturn(false);
		$this->apcu->method('isInstalled')->willReturn(true);
		$this->apcu->expects($this->once())->method('set')
			->with($key, $this->callback(fn ($v): bool => is_array($v) && $v['domain'] === 'a.example.com'), 99)
			->willReturn(true);
		$this->redis->method('isInstalled')->willReturn(true);
		$this->redis->expects($this->never())->method('set');
		$this->filesystem->expects($this->once())->method('setMandatory')
			->with($key, $this->isType('array'), 99)
			->willReturn(true);

		$this->assertTrue($this->cache()->storeLicense('license', $this->license(), 99));
	}

	public function testLicenseStoreSucceedsOnTheDiskCopyAlone(): void
	{
		$this->filesystem->method('setMandatory')->willReturn(true);

		$this->assertTrue($this->cache()->storeLicense('license', $this->license(), 99));
	}

	public function testLicenseReadFallsThroughToTheMandatoryDiskCopy(): void
	{
		$key = self::PREFIX . ':license';

		$this->apcu->method('isInstalled')->willReturn(true);
		$this->apcu->method('get')->with($key)->willReturn(null);
		$this->filesystem->expects($this->once())->method('getMandatory')->with($key)
			->willReturn(['valid' => true, 'domain' => 'a.example.com', 'edition' => 'pro']);

		$license = $this->cache()->getLicense('license');

		$this->assertInstanceOf(LicenseData::class, $license);
		$this->assertSame('a.example.com', $license->domain);
	}

	public function testLegacyLicenseObjectsStillReadBack(): void
	{
		$this->filesystem->method('getMandatory')->willReturn($this->license());

		$this->assertInstanceOf(LicenseData::class, $this->cache()->getLicense('license'));
	}

	public function testUnrecognizedLicensePayloadReadsAsMissing(): void
	{
		$this->filesystem->method('getMandatory')->willReturn(['garbage' => true]);

		$this->assertNull($this->cache()->getLicense('license'));
	}

	public function testClearLicenseHitsInstalledBackendsAndTheDiskCopyEvenWhenOneFails(): void
	{
		$key = self::PREFIX . ':license';

		$this->apcu->method('isInstalled')->willReturn(true);
		$this->apcu->expects($this->once())->method('delete')->with($key)->willReturn(false);
		$this->filesystem->expects($this->once())->method('deleteMandatory')->with($key)->willReturn(true);

		$this->assertFalse($this->cache()->clearLicense('license'));
	}

	public function testPasswordResetUsesOneConfiguredLayerUnderItsOwnPrefix(): void
	{
		$key = self::PREFIX . ':' . IdentityCache::PREFIX_PASSWORD_RESET . ':tok';

		$this->apcu->method('isAvailable')->willReturn(true);
		$this->apcu->expects($this->once())->method('set')->with($key, ['email' => 'a@b.c'], 1800)->willReturn(true);
		$this->redis->method('isAvailable')->willReturn(true);
		$this->redis->expects($this->never())->method('set');
		$this->filesystem->method('isAvailable')->willReturn(true);
		$this->filesystem->expects($this->never())->method('set');

		$this->assertTrue($this->cache()->storePasswordReset('tok', ['email' => 'a@b.c'], 1800));
	}

	public function testPasswordResetReadFallsThroughTheConfiguredBackends(): void
	{
		$key = self::PREFIX . ':' . IdentityCache::PREFIX_PASSWORD_RESET . ':tok';

		$this->apcu->method('isAvailable')->willReturn(true);
		$this->apcu->method('get')->with($key)->willReturn(null);
		$this->filesystem->method('isAvailable')->willReturn(true);
		$this->filesystem->method('get')->with($key)->willReturn(['email' => 'a@b.c']);

		$this->assertSame(['email' => 'a@b.c'], $this->cache()->getPasswordReset('tok'));
	}

	public function testPasswordResetClearHitsEveryConfiguredBackend(): void
	{
		$key = self::PREFIX . ':' . IdentityCache::PREFIX_PASSWORD_RESET . ':tok';

		$this->apcu->method('isAvailable')->willReturn(true);
		$this->apcu->expects($this->once())->method('delete')->with($key)->willReturn(true);
		$this->filesystem->method('isAvailable')->willReturn(true);
		$this->filesystem->expects($this->once())->method('delete')->with($key)->willReturn(true);

		$this->assertTrue($this->cache()->clearPasswordReset('tok'));
	}
}
