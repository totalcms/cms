<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\OAuth\Repository\OAuthReplayDetector;

final class OAuthReplayDetectorTest extends TestCase
{
	private const TTL = 2592000; // 30 days in seconds

	public function testRecordRotatedThenGetRotatedIdentityReturnsIdentity(): void
	{
		$hash     = hash('sha256', 'some-token-id');
		$clientId = 'client-abc';
		$userId   = 'user-xyz';

		$cache = $this->createMock(CacheManager::class);

		$cache->expects($this->once())
			->method('storeComputedData')
			->with(
				'oauth.replay.' . $hash,
				['client_id' => $clientId, 'user_id' => $userId],
				self::TTL,
			)
			->willReturn(true);

		$cache->expects($this->once())
			->method('getComputedData')
			->with('oauth.replay.' . $hash)
			->willReturn(['client_id' => $clientId, 'user_id' => $userId]);

		$detector = new OAuthReplayDetector($cache, self::TTL);
		$detector->recordRotated($hash, $clientId, $userId);

		$identity = $detector->getRotatedIdentity($hash);

		$this->assertNotNull($identity);
		$this->assertSame($clientId, $identity['client_id']);
		$this->assertSame($userId, $identity['user_id']);
	}

	public function testGetRotatedIdentityReturnsNullForUnseenHash(): void
	{
		$cache = $this->createMock(CacheManager::class);

		$cache->expects($this->never())
			->method('storeComputedData');

		$cache->expects($this->once())
			->method('getComputedData')
			->with('oauth.replay.unknown-hash')
			->willReturn(null);

		$detector = new OAuthReplayDetector($cache, self::TTL);

		$this->assertNull($detector->getRotatedIdentity('unknown-hash'));
	}

	public function testIdentityValuesRoundTripExactly(): void
	{
		$hash     = hash('sha256', 'round-trip-token');
		$clientId = 'my-client-id-42';
		$userId   = 'admin@example.test';

		$cache = $this->createMock(CacheManager::class);

		$cache->method('storeComputedData')->willReturn(true);
		$cache->method('getComputedData')->willReturn(['client_id' => $clientId, 'user_id' => $userId]);

		$detector = new OAuthReplayDetector($cache, self::TTL);
		$detector->recordRotated($hash, $clientId, $userId);

		$identity = $detector->getRotatedIdentity($hash);

		$this->assertNotNull($identity);
		$this->assertSame($clientId, $identity['client_id']);
		$this->assertSame($userId, $identity['user_id']);
	}

	public function testGetRotatedIdentityReturnsNullWhenCacheDataMalformed(): void
	{
		$cache = $this->createMock(CacheManager::class);

		$cache->expects($this->once())
			->method('getComputedData')
			->willReturn(['only_client_id' => 'missing-user']);

		$detector = new OAuthReplayDetector($cache, self::TTL);

		$this->assertNull($detector->getRotatedIdentity('some-hash'));
	}
}
