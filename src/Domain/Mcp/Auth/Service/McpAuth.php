<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Service;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Exception\McpAuthException;
use TotalCMS\Support\Config;

/**
 * Resolves the caller persona for an MCP request.
 *
 * Authentication is delegated to ApiKeyAuthenticator, which validates the key
 * against the request's method + path using the standard scope model. For an
 * MCP request to succeed with admin persona, the key's scopes must allow POST
 * (or GET) on `/mcp`. That means existing "All endpoints" (paths: ['*']) keys
 * automatically grant MCP access, while "specific" keys must include `/mcp` in
 * their paths — same model the REST API uses.
 *
 * When `mcp.publicAccess` is false in config, anonymous callers are rejected
 * with 401 rather than resolved to the public persona — that's the master
 * switch operators flip to lock the endpoint to API-key-only access.
 */
readonly class McpAuth
{
	public function __construct(
		private ApiKeyAuthenticator $apiKeyAuthenticator,
		private Config $config,
	) {
	}

	public function resolvePersona(ServerRequestInterface $request): McpPersona
	{
		if (!$this->apiKeyAuthenticator->hasApiKeyHeader($request)) {
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
