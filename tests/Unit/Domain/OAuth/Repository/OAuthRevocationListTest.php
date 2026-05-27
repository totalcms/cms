<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;

final class OAuthRevocationListTest extends TestCase
{
	private const TTL = 3600;

	public function testRevokeThenCheckReturnsTrue(): void
	{
		$cache = $this->createMock(CacheManager::class);

		$cache->expects($this->once())
			->method('storeComputedData')
			->with('oauth.revoked.jti.test-jti-123', true, self::TTL)
			->willReturn(true);

		$cache->expects($this->once())
			->method('getComputedData')
			->with('oauth.revoked.jti.test-jti-123')
			->willReturn(true);

		$list = new OAuthRevocationList($cache, self::TTL);
		$list->revoke('test-jti-123');

		$this->assertTrue($list->isRevoked('test-jti-123'));
	}

	public function testUnrevokedReturnsFalse(): void
	{
		$cache = $this->createMock(CacheManager::class);

		$cache->expects($this->never())
			->method('storeComputedData');

		$cache->expects($this->once())
			->method('getComputedData')
			->with('oauth.revoked.jti.unknown-jti')
			->willReturn(null);

		$list = new OAuthRevocationList($cache, self::TTL);

		$this->assertFalse($list->isRevoked('unknown-jti'));
	}
}
