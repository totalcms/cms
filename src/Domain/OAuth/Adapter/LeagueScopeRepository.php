<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Support\Config;

/**
 * Bridges league's ScopeRepositoryInterface to T3's OAuthScopeRegistry.
 *
 * finalizeScopes() performs a strict downscope intersection: the resolved
 * set is the intersection of (a) the scopes requested in the authorization
 * request, (b) the scopes the client is allowed to request, and (c) the
 * scopes the approving user can convey — admin-gated scopes (cms:admin)
 * require the user to be a super admin OR have SOME admin-domain access-group
 * grant (UserAuthority::hasAdminDomainGrants() — schemas, collectionsMeta, or
 * a utils allow), failing closed when the user is unknown. This is
 * deliberately a broad "can request at all" gate, not a fine-grained one —
 * the access-group layer still caps what the resulting token can actually do
 * (see the MCP admin-domain tools' ToolRequirement guard).
 *
 * getScopeEntityByIdentifier() is deliberately TOLERANT of unknown
 * identifiers: returning null makes league's AbstractGrant::validateScopes()
 * throw invalid_scope, hard-failing the whole authorize request — and MCP
 * clients send labels T3 doesn't define (claude.ai: `claudeai`). Unknown
 * labels are instead dropped later: OAuthAuthorizeAction normalizes the
 * parsed request (substituting oauth.defaultScope when nothing known
 * remains), and finalizeScopes()' allowed-set intersection removes anything
 * that survives to token issuance. Refresh grants reuse the already-narrowed
 * set, so this filter runs once per authorization.
 */
final readonly class LeagueScopeRepository implements ScopeRepositoryInterface
{
	public function __construct(
		private OAuthScopeRegistry $registry,
		private OAuthClientRepository $clients,
		private AccessControlService $accessControl,
		private Config $config,
	) {
	}

	public function getScopeEntityByIdentifier(string $identifier): ScopeEntityInterface
	{
		// Tolerant on purpose — see class docblock. Unknown identifiers are
		// filtered downstream, never granted.
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

		$userCanConveyAdmin = false;
		if (is_string($userIdentifier) && $userIdentifier !== '') {
			$ref = OAuthUserRef::parse($userIdentifier, (string)$this->config->auth['collection']);
			// Widened issuance gate (spec refinement): a non-admin user whose
			// groups grant ANY admin-domain permission (schemas, collectionsMeta,
			// or a utils allow) can also convey cms:admin — mirrors the same
			// authority the group layer already grants them elsewhere (e.g. MCP
			// admin-domain tools gated by ToolRequirement). The group layer still
			// caps what the resulting token can actually DO — this only widens who
			// is allowed to REQUEST the scope in the first place. isAdmin() short-
			// circuits first so the common case never pays for authorityFor()'s
			// group lookup.
			$userCanConveyAdmin = $this->accessControl->isAdmin($ref->userId, $ref->collection)
				|| $this->accessControl->authorityFor($ref)->hasAdminDomainGrants();
		}

		return array_values(array_filter(
			$scopes,
			function (ScopeEntityInterface $scope) use ($allowed, $userCanConveyAdmin): bool {
				$id = $scope->getIdentifier();
				// Registry guard: the tolerant getScopeEntityByIdentifier()
				// lets unknown labels reach this point — nothing outside the
				// registry may ever survive into a token, regardless of what
				// a client record claims to allow.
				if (!$this->registry->has($id)) {
					return false;
				}
				if (!in_array($id, $allowed, true)) {
					return false;
				}

				return !in_array($id, OAuthScopeRegistry::ADMIN_GATED, true) || $userCanConveyAdmin;
			},
		));
	}
}
