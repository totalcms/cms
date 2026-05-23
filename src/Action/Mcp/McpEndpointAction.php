<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mcp;

use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Auth\Exception\McpAuthException;
use TotalCMS\Domain\Mcp\Auth\Service\McpAuth;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
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

		// Slim's BodyParsingMiddleware reads and consumes the body stream.
		// Rewind so the SDK's StreamableHttpTransport can call getContents()
		// from the beginning of the stream.
		$request->getBody()->rewind();

		$server    = $this->serverFactory->build($persona);
		$transport = new StreamableHttpTransport($request);

		return $server->run($transport);
	}
}
