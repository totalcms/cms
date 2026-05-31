<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Repository;

use TotalCMS\Domain\Cache\CacheManager;

/**
 * Tracks revoked access-token jti's. Cache-backed (APCu when available,
 * filesystem fallback). The TTL equals the maximum access-token lifetime
 * so the list self-cleans — a jti that's older than the maximum TTL is
 * guaranteed to be expired anyway, so removing it from the list is safe.
 */
final readonly class OAuthRevocationList
{
	private const PREFIX = 'oauth.revoked.jti.';

	public function __construct(
		private CacheManager $cache,
		private int $accessTokenTtlSeconds,
	) {
	}

	public function revoke(string $jti): void
	{
		$this->cache->storeComputedData(self::PREFIX . $jti, true, $this->accessTokenTtlSeconds);
	}

	public function isRevoked(string $jti): bool
	{
		return $this->cache->getComputedData(self::PREFIX . $jti) === true;
	}
}
