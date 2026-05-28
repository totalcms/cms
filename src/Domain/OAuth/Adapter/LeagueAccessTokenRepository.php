<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;

/**
 * Bridges league's AccessTokenRepositoryInterface to T3's revocation list.
 *
 * Access tokens are stateless JWTs — T3 does not persist them. Only the jti
 * (token identifier) is tracked for revocation via OAuthRevocationList.
 * persistNewAccessToken() is intentionally a no-op.
 */
final class LeagueAccessTokenRepository implements AccessTokenRepositoryInterface
{
	public function __construct(
		private readonly OAuthRevocationList $revocationList,
	) {
	}

	/**
	 * @param ScopeEntityInterface[] $scopes
	 */
	public function getNewToken(
		ClientEntityInterface $clientEntity,
		array $scopes,
		?string $userIdentifier = null,
	): AccessTokenEntityInterface {
		$token = new LeagueAccessTokenEntity();
		$token->setClient($clientEntity);
		foreach ($scopes as $scope) {
			$token->addScope($scope);
		}
		if ($userIdentifier !== null && $userIdentifier !== '') {
			$token->setUserIdentifier($userIdentifier);
		}

		return $token;
	}

	/**
	 * No-op: access tokens are stateless JWTs, never written to storage.
	 */
	public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
	{
		// Intentional no-op.
	}

	public function revokeAccessToken(string $tokenId): void
	{
		$this->revocationList->revoke($tokenId);
	}

	public function isAccessTokenRevoked(string $tokenId): bool
	{
		return $this->revocationList->isRevoked($tokenId);
	}
}
