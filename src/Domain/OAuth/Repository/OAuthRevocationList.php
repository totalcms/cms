<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Repository;

/**
 * Tracks revoked access-token jti's. Kept in OAuthStateStore, not the
 * cache: a cache clear would un-revoke every token on the list. The TTL
 * equals the maximum access-token lifetime so the list self-cleans — a jti
 * older than that is expired anyway, so dropping it from the list is safe.
 */
final readonly class OAuthRevocationList
{
	private const BUCKET = 'revoked';

	public function __construct(
		private OAuthStateStore $state,
		private int $accessTokenTtlSeconds,
	) {
	}

	public function revoke(string $jti): void
	{
		$this->state->put(self::BUCKET, $jti, true, $this->accessTokenTtlSeconds);
	}

	public function isRevoked(string $jti): bool
	{
		return $this->state->get(self::BUCKET, $jti) === true;
	}
}
