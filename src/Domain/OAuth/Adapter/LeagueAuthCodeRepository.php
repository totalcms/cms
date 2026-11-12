<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use TotalCMS\Domain\OAuth\Repository\OAuthStateStore;

/**
 * Bridges league's AuthCodeRepositoryInterface to T3's OAuthStateStore.
 *
 * T3 does not need a code's payload: league encrypts it into the code it
 * hands the client and decrypts it on exchange. T3 tracks only whether the
 * code is still unspent, from consent until the code expires.
 *
 * A code with no entry reads as revoked: spent, expired, or never issued.
 * See OAuthStateStore for why this is not kept in the cache.
 */
final readonly class LeagueAuthCodeRepository implements AuthCodeRepositoryInterface
{
	private const BUCKET = 'authcodes';

	public function __construct(
		private OAuthStateStore $state,
	) {
	}

	public function getNewAuthCode(): AuthCodeEntityInterface
	{
		return new LeagueAuthCodeEntity();
	}

	public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
	{
		$this->state->put(
			self::BUCKET,
			$authCodeEntity->getIdentifier(),
			true,
			$authCodeEntity->getExpiryDateTime()->getTimestamp() - time(),
		);
	}

	public function revokeAuthCode(string $codeId): void
	{
		$this->state->delete(self::BUCKET, $codeId);
	}

	public function isAuthCodeRevoked(string $codeId): bool
	{
		return $this->state->get(self::BUCKET, $codeId) !== true;
	}
}
