<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mcp;

use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Exception\McpAuthException;
use TotalCMS\Domain\Mcp\Auth\Service\McpAuth;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\OAuth\Service\OAuthScopeEvaluator;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Main MCP endpoint at /mcp.
 *
 * Handles both POST (JSON-RPC requests) and GET (SSE upgrade for streaming);
 * the SDK's StreamableHttpTransport detects the method and Accept header to
 * route appropriately. We do not split into separate actions.
 *
 * Three early returns guard the endpoint before the SDK runs:
 *   - mcp.enabled false   → 404 (the endpoint should appear not to exist)
 *   - edition gate failed → 403 with a structured error body
 *   - invalid API key     → 401
 */
readonly class McpEndpointAction
{
	public function __construct(
		private McpServerFactory $serverFactory,
		private McpAuth $mcpAuth,
		private PersonaContext $personaContext,
		private EditionFeatureService $editionFeatures,
		private JsonRenderer $renderer,
		private Config $config,
		private OAuthScopeEvaluator $scopeEvaluator,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		if (!($this->config->mcp['enabled'] ?? true)) {
			return $this->renderer->json($response, [
				'error' => ['message' => 'MCP server is disabled on this site.'],
			], 404);
		}

		if (!$this->editionFeatures->can(EditionFeature::MCP_SERVER)) {
			return $this->renderer->json($response, [
				'error' => [
					'message'  => 'MCP is only available on Pro and higher editions.',
					'edition'  => $this->editionFeatures->getEdition()->value,
					'required' => 'pro',
				],
			], 403);
		}

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
				sprintf('Bearer realm="MCP", error="%s"', $e->reason),
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
					? (string) $s->getIdentifier()
					: (string) $s,
				$oauthScopes,
			));
			$this->personaContext->setScopes($scopes);
		}

		// Scope-based gate for AUTHENTICATED persona. The persona filter has
		// already trimmed the tool/resource surface to what's visible at the
		// "authenticated" access level; this check enforces that the token's
		// scopes actually grant access to the specific JSON-RPC method being
		// invoked. ADMIN and PUBLIC_ personas skip this gate — ADMIN has
		// authority via API key; PUBLIC_ is gated by the visibility filter
		// alone (no scope concept).
		if ($persona === McpPersona::AUTHENTICATED) {
			// Slim's BodyParsingMiddleware may have already consumed the body stream.
			// Try getParsedBody() first (populated when Content-Type: application/json),
			// then fall back to reading the raw stream (rewind first in case it's at 0).
			$parsed = $request->getParsedBody();
			if (is_array($parsed)) {
				$rpc = $parsed;
			} else {
				$request->getBody()->rewind();
				$bodyText = (string)$request->getBody()->getContents();
				$rpc      = json_decode($bodyText, true);
			}
			// Always rewind so the SDK's StreamableHttpTransport can call getContents()
			// from position 0.
			$request->getBody()->rewind();

			$method = is_array($rpc) && isset($rpc['method']) && is_string($rpc['method'])
				? $rpc['method']
				: '';

			// For tools/call, append the tool name so per-tool scopes can gate
			// individual invocations (foundation for future fine-grained scopes;
			// in v1 the mcp:tools scope covers all tool invocations via prefix
			// match on "tools/call").
			if ($method === 'tools/call' && isset($rpc['params']['name']) && is_string($rpc['params']['name'])) {
				$operation = 'tools/call:' . $rpc['params']['name'];
			} else {
				$operation = $method;
			}

			if ($method !== '' && !$this->scopeEvaluator->isAllowed($this->personaContext->getScopes(), $operation)) {
				$response = $this->renderer->json($response, [
					'error' => [
						'message'   => 'OAuth token scopes do not permit this MCP operation.',
						'operation' => $method,
					],
				], 403);
				return $response->withHeader(
					'WWW-Authenticate',
					'Bearer realm="MCP", error="insufficient_scope"',
				);
			}
		}

		// Slim's BodyParsingMiddleware reads and consumes the body stream.
		// Rewind so the SDK's StreamableHttpTransport can call getContents()
		// from the beginning of the stream.
		$request->getBody()->rewind();

		$server    = $this->serverFactory->build($persona);
		$transport = new StreamableHttpTransport($request);

		return $server->run($transport);
	}
}
