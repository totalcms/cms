<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Adapter;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\OAuth\Adapter\LeagueAccessTokenEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueClientEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueRefreshTokenEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueRefreshTokenRepository;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeEntity;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthReplayDetector;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;

final class LeagueRefreshTokenRepositoryTest extends TestCase
{
	private string $tmpFile;
	private OAuthGrantRepository $grantRepo;
	private LeagueRefreshTokenRepository $adapter;

	protected function setUp(): void
	{
		$this->tmpFile   = sys_get_temp_dir() . '/oauth-grants-' . uniqid() . '.json';
		$this->grantRepo = new OAuthGrantRepository($this->tmpFile);

		$cache          = $this->createMock(CacheManager::class);
		$cache->method('storeComputedData')->willReturn(true);
		$cache->method('getComputedData')->willReturn(null);
		$replayDetector = new OAuthReplayDetector($cache, 2592000);
		$activityLogger = new OAuthActivityLogger(new NullLogger());

		$this->adapter  = new LeagueRefreshTokenRepository($this->grantRepo, $replayDetector, $activityLogger);
	}

	protected function tearDown(): void
	{
		if (is_file($this->tmpFile)) {
			unlink($this->tmpFile);
		}
	}

	public function testGetNewRefreshTokenReturnsEntity(): void
	{
		$entity = $this->adapter->getNewRefreshToken();
		$this->assertInstanceOf(LeagueRefreshTokenEntity::class, $entity);
	}

	public function testPersistThenIsRevokedReturnsFalse(): void
	{
		$refreshToken = $this->makeRefreshToken('rt-identifier-1', '+30 days', 'user-1', 'c-1');

		$this->adapter->persistNewRefreshToken($refreshToken);

		$this->assertFalse($this->adapter->isRefreshTokenRevoked('rt-identifier-1'));
	}

	public function testRevokeTokenThenIsRevokedReturnsTrue(): void
	{
		$refreshToken = $this->makeRefreshToken('rt-identifier-2', '+30 days', 'user-2', 'c-1');

		$this->adapter->persistNewRefreshToken($refreshToken);
		$this->assertFalse($this->adapter->isRefreshTokenRevoked('rt-identifier-2'));

		$this->adapter->revokeRefreshToken('rt-identifier-2');
		$this->assertTrue($this->adapter->isRefreshTokenRevoked('rt-identifier-2'));
	}

	public function testIsRevokedForUnknownTokenReturnsTrue(): void
	{
		$this->assertTrue($this->adapter->isRefreshTokenRevoked('never-persisted'));
	}

	// Helpers

	private function makeRefreshToken(
		string $identifier,
		string $expiryModifier,
		string $userId,
		string $clientId,
	): LeagueRefreshTokenEntity {
		$clientData = new OAuthClientData(
			id: $clientId,
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

		$scope = new LeagueScopeEntity('cms:read');

		$accessToken = new LeagueAccessTokenEntity();
		$accessToken->setIdentifier('at-' . $identifier);
		$accessToken->setClient($clientEntity);
		$accessToken->addScope($scope);
		$accessToken->setUserIdentifier($userId);
		$accessToken->setExpiryDateTime(new \DateTimeImmutable('+1 hour'));

		$refreshToken = new LeagueRefreshTokenEntity();
		$refreshToken->setIdentifier($identifier);
		$refreshToken->setAccessToken($accessToken);
		$refreshToken->setExpiryDateTime(new \DateTimeImmutable($expiryModifier));

		return $refreshToken;
	}
}
