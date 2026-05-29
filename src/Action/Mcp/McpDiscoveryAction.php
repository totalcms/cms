<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mcp;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Public discovery endpoint at /.well-known/mcp.json.
 *
 * Always returns HTTP 200 so AI agents can discover the endpoint's status
 * deterministically rather than guessing from a 404. When MCP is unavailable
 * (disabled config or non-Pro edition) the body carries `disabled: true` plus
 * a `reason` field so agents can surface the right UX to the user.
 */
readonly class McpDiscoveryAction
{
	public function __construct(
		private McpServerFactory $serverFactory,
		private ToolRegistry $toolRegistry,
		private EditionFeatureService $editionFeatures,
		private JsonRenderer $renderer,
		private Config $config,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$enabled = (bool)($this->config->mcp['enabled'] ?? true);
		$isPro   = $this->editionFeatures->can(EditionFeature::MCP_SERVER);

		if (!$enabled || !$isPro) {
			return $this->renderer->json($response, [
				'mcpVersion' => $this->serverFactory->protocolVersion(),
				'disabled'   => true,
				'reason'     => $enabled ? 'edition' : 'config',
				'edition'    => $this->editionFeatures->getEdition()->value,
			], 200);
		}

		$publicAccess = (bool)($this->config->mcp['publicAccess'] ?? true);
		$publicTools  = [];
		if ($publicAccess) {
			foreach ($this->toolRegistry->forPersona(McpPersona::PUBLIC_) as $tool) {
				$publicTools[] = $tool->name;
			}
		}

		// Build the endpoint from the inbound request so we honour the exact
		// scheme/host the agent reached us at (proxies, dev overrides). The base
		// path is folded in via Config::mcpEndpoint() so subpath / Stacks installs
		// advertise a reachable `<base>/mcp` rather than the wrong domain-root URL.
		$uri       = $request->getUri();
		$authority = $uri->getAuthority();

		// ...except when the request reached us on a loopback/IP host (typical
		// inside Docker or behind a reverse proxy that doesn't forward Host) while
		// we have a real configured domain. Advertising 127.0.0.1 there hands
		// agents an unreachable endpoint, so fall back to the configured site URL.
		$endpoint = Config::isNonRoutableHost($authority) && !Config::isNonRoutableHost($this->config->domain)
			? $this->config->mcpEndpoint()
			: $this->config->mcpEndpoint($uri->getScheme() . '://' . $authority);

		return $this->renderer->json($response, [
			'mcpVersion'  => $this->serverFactory->protocolVersion(),
			'endpoint'    => $endpoint,
			'name'        => $this->config->domain,
			'description' => 'Total CMS site exposed as an MCP server.',
			'auth'        => [
				'public' => $publicAccess,
				'apiKey' => ['header' => 'X-API-Key'],
			],
			'publicTools' => $publicTools,
		], 200);
	}
}
