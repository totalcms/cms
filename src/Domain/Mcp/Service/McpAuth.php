<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Exception\McpAuthException;
use TotalCMS\Support\Config;

/**
 * Resolves the caller persona for an MCP request.
 *
 * The MCP surface is its own auth context, distinct from the REST API: a valid
 * API key authenticates as ADMIN regardless of the key's REST scopes. Phase 4
 * will add MCP-specific scope refinements (per-tool, per-collection).
 *
 * When `mcp.publicAccess` is false in config, anonymous callers are rejected
 * with 401 rather than resolved to the public persona — that's the master
 * switch operators flip to lock the endpoint to API-key-only access.
 */
readonly class McpAuth
{
	public function __construct(
		private ApiKeyAuthenticator $apiKeyAuthenticator,
		private ApiKeyRepository $apiKeyRepository,
		private Config $config,
	) {
	}

	public function resolvePersona(ServerRequestInterface $request): McpPersona
	{
		if (!$this->apiKeyAuthenticator->hasApiKeyHeader($request)) {
			if (!(bool)($this->config->mcp['publicAccess'] ?? false)) {
				throw new McpAuthException('Anonymous access is disabled. Provide an API key in the X-API-Key header or Authorization: Bearer.');
			}

			return McpPersona::PUBLIC_;
		}

		$key = $this->extractApiKey($request);
		if ($key === '') {
			throw new McpAuthException('Empty API key supplied.');
		}

		$apiKey = $this->apiKeyRepository->findByKey($key);
		if ($apiKey === null) {
			throw new McpAuthException('Invalid API key.');
		}

		// Phase 0/1: any valid key authenticates as admin on MCP.
		return McpPersona::ADMIN;
	}

	private function extractApiKey(ServerRequestInterface $request): string
	{
		$authHeader = $request->getHeaderLine('Authorization');
		if ($authHeader !== '' && str_starts_with($authHeader, 'Bearer ')) {
			return substr($authHeader, 7);
		}

		if ($request->hasHeader('X-API-Key')) {
			return $request->getHeaderLine('X-API-Key');
		}

		return '';
	}
}
