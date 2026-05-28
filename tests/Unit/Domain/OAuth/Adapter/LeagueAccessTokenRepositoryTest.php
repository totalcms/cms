<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\OAuth\Adapter\LeagueAccessTokenEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueAccessTokenRepository;
use TotalCMS\Domain\OAuth\Adapter\LeagueClientEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeEntity;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;

final class LeagueAccessTokenRepositoryTest extends TestCase
{
	private const TTL = 3600;

	public function testPersistNewAccessTokenIsNoOp(): void
	{
		$cache = $this->createMock(CacheManager::class);
		$cache->expects($this->never())->method('storeComputedData');

		$revocationList = new OAuthRevocationList($cache, self::TTL);
		$adapter        = new LeagueAccessTokenRepository($revocationList);

		$entity = new LeagueAccessTokenEntity();
		// Should not throw and should not interact with cache.
		$adapter->persistNewAccessToken($entity);
	}

	public function testRevokeAndIsRevokedRoundTrip(): void
	{
		$cache = $this->createMock(CacheManager::class);
		$cache->expects($this->once())
			->method('storeComputedData')
			->with('oauth.revoked.jti.tok-abc', true, self::TTL)
			->willReturn(true);
		$cache->expects($this->once())
			->method('getComputedData')
			->with('oauth.revoked.jti.tok-abc')
			->willReturn(true);

		$revocationList = new OAuthRevocationList($cache, self::TTL);
		$adapter        = new LeagueAccessTokenRepository($revocationList);

		$adapter->revokeAccessToken('tok-abc');
		$this->assertTrue($adapter->isAccessTokenRevoked('tok-abc'));
	}

	public function testIsAccessTokenRevokedReturnsFalseForFreshToken(): void
	{
		$cache = $this->createMock(CacheManager::class);
		$cache->expects($this->once())
			->method('getComputedData')
			->with('oauth.revoked.jti.fresh-tok')
			->willReturn(null);

		$revocationList = new OAuthRevocationList($cache, self::TTL);
		$adapter        = new LeagueAccessTokenRepository($revocationList);

		$this->assertFalse($adapter->isAccessTokenRevoked('fresh-tok'));
	}

	public function testGetNewTokenReturnsEntityWithCorrectClientAndScopes(): void
	{
		$cache = $this->createMock(CacheManager::class);

		$revocationList = new OAuthRevocationList($cache, self::TTL);
		$adapter        = new LeagueAccessTokenRepository($revocationList);

		$clientData = new OAuthClientData(
			id: 'c-1',
			name: 'Test',
			secretHash: 'hash',
			redirectUris: ['https://x.test/cb'],
			scopes: ['cms:read'],
			isDynamic: false,
			isConfidential: true,
			createdAt: '2026-05-24T00:00:00Z',
			createdBy: 'admin',
		);
		$clientEntity = new LeagueClientEntity($clientData);
		$scope        = new LeagueScopeEntity('cms:read');

		$token = $adapter->getNewToken($clientEntity, [$scope], 'user-1');

		$this->assertInstanceOf(LeagueAccessTokenEntity::class, $token);
		$this->assertSame('c-1', $token->getClient()->getIdentifier());
		$this->assertSame('user-1', $token->getUserIdentifier());
		$scopes = $token->getScopes();
		$this->assertCount(1, $scopes);
		$this->assertSame('cms:read', $scopes[0]->getIdentifier());
	}
}
