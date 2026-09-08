<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Exception\McpAuthException;
use TotalCMS\Domain\Mcp\Auth\Service\McpAuth;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthScopeEvaluator;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Everything /mcp does between the edition gate and building the server:
 * resolve the caller's persona (401 with WWW-Authenticate on failure), stash
 * it and the Bearer token's scopes / client id / user id / authority in
 * PersonaContext for the tool handlers, and enforce the AUTHENTICATED
 * persona's scope gate (403 with insufficient_scope). Returns the persona
 * to proceed with, or the finished error response.
 */
final readonly class McpRequestAuthorizer
{
	public function __construct(
		private McpAuth $mcpAuth,
		private PersonaContext $personaContext,
		private OAuthScopeEvaluator $scopeEvaluator,
		private OAuthActivityLogger $activityLogger,
		private McpUrlBuilder $urlBuilder,
		private AccessControlService $accessControl,
		private Config $config,
		private JsonRenderer $renderer,
	) {
	}

	public function authorize(ServerRequestInterface $request, ResponseInterface $response, McpRequestBody $body): McpPersona|ResponseInterface
	{
		try {
			$persona = $this->mcpAuth->resolvePersona($request);
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

		// Stash the persona so individual tool handlers can read it during
		// dispatch. Must happen before build() since the SDK invokes handlers
		// synchronously from inside the server->run() call below.
		$this->personaContext->set($persona);

		// For Bearer / OAuth requests capture the validated scopes into
		// PersonaContext so OAuthScopeEvaluator can read them during tool
		// dispatch without needing direct access to the PSR-7 request.
		// Non-Bearer paths leave oauth_scopes null; the context keeps its
		// default empty array.
		$oauthScopes = $request->getAttribute('oauth_scopes');
		if (is_array($oauthScopes)) {
			/** @var list<string> $scopes */
			$scopes = array_values(array_map(
				static fn (mixed $s): string => is_object($s) && method_exists($s, 'getIdentifier')
					? (string)$s->getIdentifier()
					: (string)$s,
				$oauthScopes,
			));
			$this->personaContext->setScopes($scopes);

			// Client id, for oauth-activity log attribution when Task 7's
			// call-time guard denies a tools/call — same request attribute
			// BaseAccessMiddleware reads for the REST equivalent.
			$this->personaContext->setClientId((string)$request->getAttribute('oauth_client_id', ''));

			// Resolve the caller's UserAuthority for this Bearer request so
			// ToolRegistry::forPersona() (via McpServerFactory) can show
			// requirement-gated tools (McpToolDefinition::$requires,
			// introduced Phase 4 Task 6) to callers whose access-group grants
			// satisfy them, and so Task 7's call-time guard can re-check
			// per invocation. Session-free — mirrors BaseAccessMiddleware's
			// OAuth Bearer branch. Non-Bearer paths (API key / anonymous)
			// leave PersonaContext's authority at its default null.
			$userId = $request->getAttribute('oauth_user_id');
			if (is_string($userId) && $userId !== '') {
				$this->personaContext->setUserId($userId);
				$ref = OAuthUserRef::parse($userId, (string)$this->config->auth['collection']);
				$this->personaContext->setAuthority($this->accessControl->authorityFor($ref));
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
				$clientId = (string)$request->getAttribute('oauth_client_id', '');
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
}
