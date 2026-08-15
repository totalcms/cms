<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Service;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Exception\McpAuthException;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Support\Config;

/**
 * Resolves the caller persona for an MCP request.
 *
 * Resolution order:
 *   1. OAuth Bearer — OAuthBearerMiddleware (mounted upstream) validates the
 *      JWT and sets `oauth_scopes` as a request attribute. McpAuth reads that
 *      attribute; it does not touch the ResourceServer directly. Bearer takes
 *      precedence over all other checks because the middleware already paid the
 *      validation cost. A token with at least one `mcp:*` scope resolves to
 *      AUTHENTICATED; a valid token with no `mcp:*` scopes throws
 *      insufficient_scope. A token whose authorizing user is in the admin
 *      group AND that carries cms:admin elevates to ADMIN (see below).
 *   2. API key — ApiKeyAuthenticator validates the X-API-Key / Authorization
 *      header against stored keys and path/method scopes. A valid key resolves
 *      to ADMIN.
 *   3. Anonymous — resolves to PUBLIC_ when mcp.publicAccess is true; throws
 *      login_required otherwise.
 *
 * When `mcp.publicAccess` is false in config, anonymous callers are rejected
 * with 401 rather than resolved to the public persona — that's the master
 * switch operators flip to lock the endpoint to API-key-only access.
 *
 * ## Editions
 *
 * The MCP endpoint itself is available on every edition, but only the PUBLIC_
 * persona is. Both privileged paths are edition-gated here, at the point the
 * persona is decided:
 *
 *   - API key  -> requires EditionFeature::API_KEYS  (Pro)
 *   - OAuth    -> requires EditionFeature::OAUTH_SERVER (Pro)
 *
 * The gate lives here rather than only on the routes that manage keys and
 * clients, because a credential can outlive the licence that created it — a
 * lapsed Pro trial, a downgrade, a restored backup, a copied `tcms-data` all
 * leave a valid key or token behind. Without this check such a credential would
 * resolve ADMIN on a licence entitled to anonymous reads only, which is the one
 * way "Lite and Standard are read-only" could quietly fail to be true.
 *
 * A credential presented on an edition that does not include it is treated as
 * absent, not as an error: the caller falls through to the anonymous branch and
 * gets PUBLIC_ (or login_required if public access is off). Failing closed to
 * "you are anonymous" is both accurate and useful — the request still works for
 * anything genuinely public.
 */
readonly class McpAuth
{
	public function __construct(
		private ApiKeyAuthenticator $apiKeyAuthenticator,
		private AccessControlService $accessControl,
		private Config $config,
		private EditionFeatureService $editionFeatures,
	) {
	}

	public function resolvePersona(ServerRequestInterface $request): McpPersona
	{
		// ── 1. OAuth Bearer path ────────────────────────────────────────────────
		// OAuthBearerMiddleware (upstream) validates the JWT and sets oauth_scopes
		// on the request when a Bearer header is present. We read the attribute
		// rather than calling ResourceServer directly — single responsibility.
		$oauthScopes = $request->getAttribute('oauth_scopes');
		if (is_array($oauthScopes) && $this->editionFeatures->can(EditionFeature::OAUTH_SERVER)) {
			// League may pass Scope entity objects or plain strings depending on
			// the version; normalise to a list<string>.
			$scopes = array_values(array_map(
				static fn (mixed $s): string => is_object($s) && method_exists($s, 'getIdentifier')
					? (string)$s->getIdentifier()
					: (string)$s,
				$oauthScopes,
			));

			$hasMcpScope = false;
			foreach ($scopes as $scope) {
				if (str_starts_with($scope, 'mcp:')) {
					$hasMcpScope = true;
					break;
				}
			}

			if (!$hasMcpScope) {
				throw new McpAuthException(
					'OAuth token lacks an mcp:* scope required for MCP access.',
					reason: 'insufficient_scope',
				);
			}

			// Super-admin elevation: identity AND scope, never either alone.
			// The sub claim proves who approved the grant (the consent screen
			// requires their login); cms:admin proves what they approved — the
			// consent screen showed "Administer your site". An admin who granted
			// a read-only token gets exactly the read-only assistant they chose,
			// and a non-admin requesting cms:admin fails the identity check and
			// stays AUTHENTICATED. Elevated tokens beat API keys on posture:
			// per-grant revocation, activity log, 1-hour expiry.
			$userId = $request->getAttribute('oauth_user_id');
			if (
				in_array('cms:admin', $scopes, true)
				&& is_string($userId) && $userId !== ''
			) {
				$ref = OAuthUserRef::parse($userId, (string)$this->config->auth['collection']);
				if ($this->accessControl->isAdmin($ref->userId, $ref->collection)) {
					return McpPersona::ADMIN;
				}
			}

			return McpPersona::AUTHENTICATED;
		}

		// ── 2. API key path ─────────────────────────────────────────────────────
		// Skipped entirely below Pro: a key that outlived its licence must not
		// grant the ADMIN persona. Treated as absent rather than invalid, so the
		// caller falls through to anonymous and still gets whatever is public.
		$apiKeysAvailable = $this->editionFeatures->can(EditionFeature::API_KEYS);

		if (!$apiKeysAvailable || !$this->apiKeyAuthenticator->hasApiKeyHeader($request)) {
			if (!(bool)($this->config->mcp['publicAccess'] ?? false)) {
				throw new McpAuthException(
					'Anonymous access is disabled. Provide an API key in the X-API-Key header or Authorization: Bearer.',
					reason: 'login_required',
				);
			}

			return McpPersona::PUBLIC_;
		}

		// authenticate() handles extract + repo lookup + method/path scope check
		// in one pass. Null means: invalid key, OR a valid key whose scopes don't
		// permit the request method/path. We collapse both into the same error
		// message — a key that's valid for REST but lacks `/mcp` is functionally
		// identical to "no MCP access" from the caller's perspective.
		$apiKey = $this->apiKeyAuthenticator->authenticate($request);
		if (!$apiKey instanceof \TotalCMS\Domain\ApiKey\Data\ApiKeyData) {
			throw new McpAuthException(
				'Invalid API key or insufficient permissions for MCP access.',
				reason: 'invalid_token',
			);
		}

		return McpPersona::ADMIN;
	}
}
