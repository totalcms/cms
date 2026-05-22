<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mcp;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Data\McpPersona;
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

		// Build endpoint URL from the inbound request so we honour the exact
		// scheme/host/base-path the agent reached us at — avoiding mismatches
		// between configured `url` / `api` and the host the agent connected to
		// (e.g. proxies, dev overrides, subpath installs).
		$uri       = $request->getUri();
		$authority = $uri->getAuthority();
		$endpoint  = $uri->getScheme() . '://' . $authority . '/mcp';

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
