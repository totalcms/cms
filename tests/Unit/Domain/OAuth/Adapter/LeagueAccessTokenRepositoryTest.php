<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Adapter\LeagueAccessTokenEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueAccessTokenRepository;
use TotalCMS\Domain\OAuth\Adapter\LeagueClientEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeEntity;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;
use TotalCMS\Domain\OAuth\Repository\OAuthStateStore;

final class LeagueAccessTokenRepositoryTest extends TestCase
{
	private const TTL = 3600;

	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/oauth-access-token-test-' . uniqid('', true);
	}

	protected function tearDown(): void
	{
		recursiveDelete($this->dir);
	}

	private function revocationList(): OAuthRevocationList
	{
		return new OAuthRevocationList(new OAuthStateStore($this->dir), self::TTL);
	}

	public function testPersistNewAccessTokenIsNoOp(): void
	{
		$adapter = new LeagueAccessTokenRepository($this->revocationList());

		// Should not throw and should write nothing: tokens are JWTs.
		$adapter->persistNewAccessToken(new LeagueAccessTokenEntity());
		$this->assertDirectoryDoesNotExist($this->dir);
	}

	public function testRevokeAndIsRevokedRoundTrip(): void
	{
		$adapter = new LeagueAccessTokenRepository($this->revocationList());

		$adapter->revokeAccessToken('tok-abc');
		$this->assertTrue($adapter->isAccessTokenRevoked('tok-abc'));
	}

	public function testIsAccessTokenRevokedReturnsFalseForFreshToken(): void
	{
		$adapter = new LeagueAccessTokenRepository($this->revocationList());

		$this->assertFalse($adapter->isAccessTokenRevoked('fresh-tok'));
	}

	public function testGetNewTokenReturnsEntityWithCorrectClientAndScopes(): void
	{
		$adapter = new LeagueAccessTokenRepository($this->revocationList());

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
