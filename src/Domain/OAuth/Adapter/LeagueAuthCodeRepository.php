<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use TotalCMS\Domain\Cache\CacheManager;

/**
 * Bridges league's AuthCodeRepositoryInterface to T3's CacheManager.
 *
 * Authorization codes are short-lived (10 minutes). T3 does not need to
 * inspect their payload — league encrypts the code before handing it to
 * the client and decrypts it on exchange. T3 only tracks existence vs.
 * revocation via a cache flag.
 *
 * Cache key format: `oauth.authcode.{identifier}`
 * Value: `true`  = valid (not yet revoked)
 *        `false` = revoked
 * Missing entry   = treated as revoked (expired or never issued)
 */
final class LeagueAuthCodeRepository implements AuthCodeRepositoryInterface
{
	private const PREFIX = 'oauth.authcode.';
	private const TTL    = 600; // 10 minutes

	public function __construct(
		private readonly CacheManager $cache,
	) {
	}

	public function getNewAuthCode(): AuthCodeEntityInterface
	{
		return new LeagueAuthCodeEntity();
	}

	public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
	{
		$this->cache->storeComputedData(
			self::PREFIX . $authCodeEntity->getIdentifier(),
			true,
			self::TTL,
		);
	}

	public function revokeAuthCode(string $codeId): void
	{
		// Store a false sentinel — treats the code as permanently revoked
		// for the remainder of the TTL window.
		$this->cache->storeComputedData(
			self::PREFIX . $codeId,
			false,
			self::TTL,
		);
	}

	public function isAuthCodeRevoked(string $codeId): bool
	{
		$value = $this->cache->getComputedData(self::PREFIX . $codeId);

		// Missing entry (null) or false sentinel → revoked.
		return $value !== true;
	}
}
