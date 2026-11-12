<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Repository;

/**
 * Tracks refresh-token hashes that have been rotated out so a later
 * presentation of the same token signals theft. Kept in OAuthStateStore,
 * not the cache, which a cache clear would empty and blind the check. The
 * TTL equals the refresh-token lifetime — after that the hash falls out, but by
 * then the token would be expired anyway.
 *
 * Independent of OAuthRevocationList (which tracks access-token jti's).
 * Refresh tokens get rotated on each use; access tokens get revoked
 * explicitly via /oauth/revoke or via this replay-detection cascade.
 */
final readonly class OAuthReplayDetector
{
	private const BUCKET = 'replay';

	public function __construct(
		private OAuthStateStore $state,
		private int $refreshTokenTtlSeconds,
	) {
	}

	/**
	 * Record that this refresh-token hash was rotated out as part of a
	 * normal refresh-token-grant cycle. A later attempt to use this hash
	 * is a replay.
	 */
	public function recordRotated(string $hash, string $clientId, string $userId): void
	{
		$this->state->put(
			self::BUCKET,
			$hash,
			['client_id' => $clientId, 'user_id' => $userId],
			$this->refreshTokenTtlSeconds,
		);
	}

	/**
	 * Returns the client+user identity for a hash that was previously rotated
	 * out (replay detected), or null if this hash was never seen.
	 *
	 * @return array{client_id: string, user_id: string}|null
	 */
	public function getRotatedIdentity(string $hash): ?array
	{
		$data = $this->state->get(self::BUCKET, $hash);
		if (!is_array($data) || !isset($data['client_id'], $data['user_id'])) {
			return null;
		}

		return [
			'client_id' => (string)$data['client_id'],
			'user_id'   => (string)$data['user_id'],
		];
	}
}
