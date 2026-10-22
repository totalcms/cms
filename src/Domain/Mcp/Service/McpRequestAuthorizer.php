<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyGrants;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Mcp\Auth\Data\McpCaller;
use TotalCMS\Domain\Mcp\Auth\Data\McpCallerKind;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Exception\McpAuthException;
use TotalCMS\Domain\Mcp\Auth\Service\McpAuth;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthScopeEvaluator;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Everything /mcp does between the edition gate and building the server:
 * resolve the caller's persona (401 with WWW-Authenticate on failure), make
 * the caller the request's current {@see Actor} (so the storage layer applies
 * their access rules to every write a tool makes), stash the persona and the
 * Bearer token's scopes / client id / user id / authority in PersonaContext
 * for the tool handlers, and enforce the AUTHENTICATED
 * persona's scope gate (403 with insufficient_scope). Returns the persona
 * to proceed with, or the finished error response.
 */
final readonly class McpRequestAuthorizer
{
	/**
	 * A limited API key's scopes over MCP: all of them. What it may touch is
	 * decided by its grants, not its scopes.
	 */
	public const KEY_SCOPES = ['cms:read', 'cms:write', 'cms:admin', 'mcp:tools', 'mcp:resources', 'mcp:prompts'];

	public function __construct(
		private McpAuth $mcpAuth,
		private PersonaContext $personaContext,
		private OAuthScopeEvaluator $scopeEvaluator,
		private OAuthActivityLogger $activityLogger,
		private McpUrlBuilder $urlBuilder,
		private ActorResolver $actorResolver,
		private ActorContext $actors,
		private JsonRenderer $renderer,
	) {
	}

	public function authorize(ServerRequestInterface $request, ResponseInterface $response, McpRequestBody $body): McpPersona|ResponseInterface
	{
		try {
			$caller = $this->mcpAuth->resolveCaller($request);
		} catch (McpAuthException $e) {
			// WWW-Authenticate triggers lazy-auth UX in MCP clients — the host
			// knows whether to prompt for credentials (login_required) vs surface
			// a "your token didn't work" message (invalid_token). Required for
			// Anthropic Directory submission.
			$response = $this->renderer->json($response, [
				'error' => ['message' => $e->getMessage()],
			], 401);

			return $response->withHeader(
				'WWW-Authenticate',
				sprintf(
					'Bearer realm="MCP", error="%s", resource_metadata="%s"',
					$e->reason,
					$this->urlBuilder->protectedResourceMetadataUrl($request),
				),
			);
		}

		$persona = $caller->persona;
		$actor   = $this->actorFor($caller, $request);
		$this->actors->set($actor);

		// Stash the persona so individual tool handlers can read it during
		// dispatch. Must happen before build() since the SDK invokes handlers
		// synchronously from inside the server->run() call below.
		$this->personaContext->set($persona);
		$this->personaContext->setCallerKind($caller->kind);

		// A browser session (WebMCP) is equipped like a read-only OAuth grant
		// the user approved: read scope, the tool surface, and the user's own
		// access-group authority, so every gate below behaves as for a Bearer
		// caller. The client id names the surface in the activity log.
		if ($caller->kind === McpCallerKind::Session) {
			$this->personaContext->setScopes(McpAuth::SESSION_SCOPES);
			$this->personaContext->setClientId('webmcp');
			$this->personaContext->setUserId($caller->userRef);
			$this->personaContext->setAuthority($actor->authority());
		}

		// A key limited to some collections works like an OAuth grant over
		// them: every scope (its methods do not apply over MCP, where every
		// call is a POST) and the grants its paths name.
		$this->personaContext->setLimitedKey($caller->limitedKey);
		if ($caller->limitedKey instanceof ApiKeyData) {
			$this->personaContext->setScopes(self::KEY_SCOPES);
			$this->personaContext->setClientId('apikey:' . $caller->apiKeyId);
			$this->personaContext->setAuthority($actor->authority());
		}

		// For Bearer / OAuth requests capture the validated scopes into
		// PersonaContext so OAuthScopeEvaluator can read them during tool
		// dispatch without needing direct access to the PSR-7 request.
		// Non-Bearer paths leave oauth_scopes null; the context keeps its
		// default empty array.
		$oauthScopes = $request->getAttribute('oauth_scopes');
		if (is_array($oauthScopes)) {
			$this->personaContext->setScopes(ActorResolver::scopeIds($oauthScopes));

			// Client id, for oauth-activity log attribution when Task 7's
			// call-time guard denies a tools/call — same request attribute
			// BaseAccessMiddleware reads for the REST equivalent.
			$this->personaContext->setClientId((string)$request->getAttribute('oauth_client_id', ''));

			// The caller's access-group authority, so ToolRegistry::forPersona()
			// (via McpServerFactory) can show requirement-gated tools to callers
			// whose grants satisfy them, and so the call-time guard can re-check
			// per invocation. API key and anonymous callers leave it null.
			if ($actor->userId !== '') {
				$this->personaContext->setUserId((string)$request->getAttribute('oauth_user_id'));
				$this->personaContext->setAuthority($actor->authority());
			}
		}

		// Scope-based gate for AUTHENTICATED persona. The persona filter has
		// already trimmed the tool/resource surface to what's visible at the
		// "authenticated" access level; this check enforces that the token's
		// scopes actually grant access to the specific JSON-RPC method being
		// invoked. ADMIN and PUBLIC_ personas skip this gate — ADMIN has
		// authority via API key; PUBLIC_ is gated by the visibility filter
		// alone (no scope concept).
		if ($persona === McpPersona::AUTHENTICATED) {
			$method      = $body->method();
			$operation   = $body->operation();
			$isLifecycle = $body->isLifecycle();

			if ($method !== '' && !$isLifecycle && !$this->scopeEvaluator->isAllowed($this->personaContext->getScopes(), $operation)) {
				$clientId = $this->personaContext->getClientId();
				$this->activityLogger->scopeRejected($clientId, $operation, $this->personaContext->getScopes());

				$response = $this->renderer->json($response, [
					'error' => [
						'message'   => 'OAuth token scopes do not permit this MCP operation.',
						'operation' => $method,
					],
				], 403);

				return $response->withHeader(
					'WWW-Authenticate',
					sprintf(
						'Bearer realm="MCP", error="insufficient_scope", resource_metadata="%s"',
						$this->urlBuilder->protectedResourceMetadataUrl($request),
					),
				);
			}
		}

		return $persona;
	}

	/**
	 * The caller as an {@see Actor}: an API key, the OAuth token's user, the
	 * browser session's user (WebMCP), or anonymous.
	 */
	private function actorFor(McpCaller $caller, ServerRequestInterface $request): Actor
	{
		return match ($caller->kind) {
			McpCallerKind::ApiKey    => Actor::apiKey($caller->apiKeyId, $caller->limitedKey instanceof ApiKeyData ? ApiKeyGrants::authority($caller->limitedKey) : null),
			McpCallerKind::OAuth     => $this->actorResolver->fromBearer(
				(string)$request->getAttribute('oauth_user_id', ''),
				ActorResolver::scopeIds($request->getAttribute('oauth_scopes')),
				(string)$request->getAttribute('oauth_client_id', ''),
			),
			McpCallerKind::Session   => $this->actorResolver->fromBearer($caller->userRef, McpAuth::SESSION_SCOPES, 'webmcp'),
			McpCallerKind::Anonymous => Actor::anonymous(),
		};
	}
}
