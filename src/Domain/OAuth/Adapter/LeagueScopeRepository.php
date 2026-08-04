<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;

/**
 * Bridges league's ScopeRepositoryInterface to T3's OAuthScopeRegistry.
 *
 * finalizeScopes() performs a strict downscope intersection: the resolved
 * set is the intersection of (a) the scopes requested in the authorization
 * request, (b) the scopes the client is allowed to request, and (c) the
 * scopes the approving user can convey — admin-gated scopes (cms:admin)
 * require the user to be in the admin group, failing closed when the user
 * is unknown. Scopes not in the T3 registry are silently removed by
 * getScopeEntityByIdentifier() before finalizeScopes() is called by
 * league's grant code. Refresh grants reuse the already-narrowed set, so
 * this filter runs once per authorization.
 */
final readonly class LeagueScopeRepository implements ScopeRepositoryInterface
{
	public function __construct(
		private OAuthScopeRegistry $registry,
		private OAuthClientRepository $clients,
		private AccessControlService $accessControl,
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
	 *
	 * @return ScopeEntityInterface[]
	 */
	public function finalizeScopes(
		array $scopes,
		string $grantType,
		ClientEntityInterface $clientEntity,
		?string $userIdentifier = null,
		?string $authCodeId = null,
	): array {
		$client = $this->clients->find($clientEntity->getIdentifier());
		if (!$client instanceof \TotalCMS\Domain\OAuth\Data\OAuthClientData) {
			return [];
		}

		$allowed = $client->scopes;

		$userCanConveyAdmin = is_string($userIdentifier)
			&& $userIdentifier !== ''
			&& $this->accessControl->isAdmin($userIdentifier);

		return array_values(array_filter(
			$scopes,
			static function (ScopeEntityInterface $scope) use ($allowed, $userCanConveyAdmin): bool {
				$id = $scope->getIdentifier();
				if (!in_array($id, $allowed, true)) {
					return false;
				}
				if (in_array($id, OAuthScopeRegistry::ADMIN_GATED, true) && !$userCanConveyAdmin) {
					return false;
				}

				return true;
			},
		));
	}
}
