<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\OAuth\Adapter\LeagueAuthCodeEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueAuthCodeRepository;

final class LeagueAuthCodeRepositoryTest extends TestCase
{
	private const PREFIX = 'oauth.authcode.';
	private const TTL    = 600;

	public function testPersistThenIsRevokedReturnsFalse(): void
	{
		$cache = $this->createMock(CacheManager::class);
		$cache->expects($this->once())
			->method('storeComputedData')
			->with(self::PREFIX . 'code-1', true, self::TTL)
			->willReturn(true);
		$cache->expects($this->once())
			->method('getComputedData')
			->with(self::PREFIX . 'code-1')
			->willReturn(true);

		$adapter = new LeagueAuthCodeRepository($cache);

		$entity = new LeagueAuthCodeEntity();
		$entity->setIdentifier('code-1');
		$adapter->persistNewAuthCode($entity);

		$this->assertFalse($adapter->isAuthCodeRevoked('code-1'));
	}

	public function testRevokeAuthCodeThenIsRevokedReturnsTrue(): void
	{
		$cache = $this->createMock(CacheManager::class);

		// persistNewAuthCode stores true; revokeAuthCode stores false.
		$cache->expects($this->exactly(2))
			->method('storeComputedData')
			->willReturn(true);

		// isAuthCodeRevoked checks after revocation — returns false sentinel.
		$cache->expects($this->once())
			->method('getComputedData')
			->with(self::PREFIX . 'code-2')
			->willReturn(false);

		$adapter = new LeagueAuthCodeRepository($cache);

		$entity = new LeagueAuthCodeEntity();
		$entity->setIdentifier('code-2');
		$adapter->persistNewAuthCode($entity);
		$adapter->revokeAuthCode('code-2');

		$this->assertTrue($adapter->isAuthCodeRevoked('code-2'));
	}

	public function testIsRevokedForUnknownCodeReturnsTrue(): void
	{
		$cache = $this->createMock(CacheManager::class);
		$cache->expects($this->once())
			->method('getComputedData')
			->with(self::PREFIX . 'unknown')
			->willReturn(null);

		$adapter = new LeagueAuthCodeRepository($cache);

		$this->assertTrue($adapter->isAuthCodeRevoked('unknown'));
	}

	public function testGetNewAuthCodeReturnsEntity(): void
	{
		$cache   = $this->createMock(CacheManager::class);
		$adapter = new LeagueAuthCodeRepository($cache);

		$entity = $adapter->getNewAuthCode();
		$this->assertInstanceOf(LeagueAuthCodeEntity::class, $entity);
	}
}
