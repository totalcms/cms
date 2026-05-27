<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;

/**
 * Bridges league's ScopeRepositoryInterface to T3's OAuthScopeRegistry.
 *
 * finalizeScopes() performs a strict downscope intersection: the resolved
 * set is the intersection of (a) the scopes requested in the authorization
 * request and (b) the scopes the client is allowed to request. Scopes not
 * in the T3 registry are silently removed by getScopeEntityByIdentifier()
 * before finalizeScopes() is called by league's grant code.
 */
final class LeagueScopeRepository implements ScopeRepositoryInterface
{
	public function __construct(
		private readonly OAuthScopeRegistry $registry,
		private readonly OAuthClientRepository $clients,
	) {
	}

	public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
	{
		if (!$this->registry->has($identifier)) {
			return null;
		}
		return new LeagueScopeEntity($identifier);
	}

	/**
	 * @param  ScopeEntityInterface[]  $scopes
	 * @return ScopeEntityInterface[]
	 */
	public function finalizeScopes(
		array $scopes,
		string $grantType,
		ClientEntityInterface $clientEntity,
		string|null $userIdentifier = null,
		?string $authCodeId = null,
	): array {
		$client = $this->clients->find($clientEntity->getIdentifier());
		if ($client === null) {
			return [];
		}

		$allowed = $client->scopes;

		return array_values(array_filter(
			$scopes,
			static fn (ScopeEntityInterface $scope): bool => in_array($scope->getIdentifier(), $allowed, true),
		));
	}
}
