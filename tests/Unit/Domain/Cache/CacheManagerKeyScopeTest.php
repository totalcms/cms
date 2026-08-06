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
 * Cache keys for content follow the `cache.domainScoped` toggle so installs
 * sharing one tcms-data share one namespace. Keys carrying per-domain identity
 * (license, session, password reset) stay pinned to the domain in both modes.
 */
final class CacheManagerKeyScopeTest extends TestCase
{
	private string $datadir = '/srv/shared/tcms-data';

	private function makeConfig(string $domain, bool $domainScoped): Config
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();

		$config->domain   = $domain;
		$config->datadir  = $this->datadir;
		$config->cachedir = '/srv/site-a/cache';
		$config->cache    = ['domainScoped' => $domainScoped];

		return $config;
	}

	private function makeManager(Config $config, ?FilesystemService $filesystem = null): CacheManager
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')
			->willReturn($this->createMock(\Psr\Log\LoggerInterface::class));

		return new CacheManager(
			$filesystem ?? $this->createMock(FilesystemService::class),
			$this->createMock(OPcacheService::class),
			$this->createMock(RedisService::class),
			$this->createMock(MemcachedService::class),
			$this->createMock(APCuService::class),
			$this->createMock(WatermarkCleanupService::class),
			$this->createMock(DevModeManager::class),
			$this->createMock(CacheInvalidationSignal::class),
			new EventDispatcher($this->createMock(\Psr\Log\LoggerInterface::class)),
			$config,
			$loggerFactory,
		);
	}

	public function testScopedModeNamespacesContentKeysByDomain(): void
	{
		$manager = $this->makeManager($this->makeConfig('a.example.com', true));

		$this->assertSame(
			md5('a.example.com') . ':collection:blog',
			$manager->applyDomainPrefix('collection:blog')
		);
	}

	public function testSharedModeNamespacesContentKeysByDataDirectory(): void
	{
		$manager = $this->makeManager($this->makeConfig('a.example.com', false));

		$this->assertSame(
			md5($this->datadir) . ':collection:blog',
			$manager->applyDomainPrefix('collection:blog')
		);
	}

	public function testSharedModeGivesTwoDomainsTheSameContentKey(): void
	{
		$a = $this->makeManager($this->makeConfig('a.example.com', false));
		$b = $this->makeManager($this->makeConfig('b.example.com', false));

		$this->assertSame(
			$a->applyDomainPrefix('collection:blog'),
			$b->applyDomainPrefix('collection:blog')
		);
	}

	public function testScopedModeGivesTwoDomainsDifferentContentKeys(): void
	{
		$a = $this->makeManager($this->makeConfig('a.example.com', true));
		$b = $this->makeManager($this->makeConfig('b.example.com', true));

		$this->assertNotSame(
			$a->applyDomainPrefix('collection:blog'),
			$b->applyDomainPrefix('collection:blog')
		);
	}

	public function testLicenseKeysStayDomainScopedInSharedMode(): void
	{
		$filesystem = $this->createMock(FilesystemService::class);
		$filesystem->expects($this->once())
			->method('getMandatory')
			->with($this->stringStartsWith(md5('a.example.com') . ':'))
			->willReturn(null);

		$manager = $this->makeManager($this->makeConfig('a.example.com', false), $filesystem);

		$manager->getLicenseData('license');
	}

	public function testSessionKeysStayDomainScopedInSharedMode(): void
	{
		$filesystem = $this->createMock(FilesystemService::class);
		$filesystem->method('isAvailable')->willReturn(true);
		$filesystem->expects($this->once())
			->method('get')
			->with($this->stringStartsWith(md5('a.example.com') . ':'))
			->willReturn(null);

		$manager = $this->makeManager($this->makeConfig('a.example.com', false), $filesystem);

		$manager->getSessionData('abc123');
	}

	public function testApplyDomainPrefixLeavesAnAlreadyPrefixedIdentityKeyAlone(): void
	{
		$manager = $this->makeManager($this->makeConfig('a.example.com', false));

		$identityKey = md5('a.example.com') . ':password_reset:tok';

		$this->assertSame($identityKey, $manager->applyDomainPrefix($identityKey));
	}

	public function testApplyDomainPrefixIsIdempotentForContentKeys(): void
	{
		$manager = $this->makeManager($this->makeConfig('a.example.com', false));

		$once = $manager->applyDomainPrefix('collection:blog');

		$this->assertSame($once, $manager->applyDomainPrefix($once));
	}
}
