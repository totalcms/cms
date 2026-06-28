<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Support\Config;

/**
 * Derives request-aware base URLs for MCP-related well-known endpoints.
 *
 * Both the main MCP endpoint and the discovery action need to resolve the
 * correct scheme+host for the public-facing server, accounting for reverse
 * proxies and Docker environments where the inbound Host is non-routable.
 * This service is the single source of truth for that logic.
 */
readonly class McpUrlBuilder
{
	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * Resolves the scheme://host base URL from the inbound request, falling
	 * back to the configured site URL when the request host is non-routable
	 * (Docker, reverse proxy that doesn't forward Host).
	 */
	public function baseUrl(ServerRequestInterface $request): string
	{
		$uri       = $request->getUri();
		$authority = $uri->getAuthority();

		if (Config::isNonRoutableHost($authority) && !Config::isNonRoutableHost($this->config->domain)) {
			return rtrim($this->config->url, '/');
		}

		return rtrim($uri->getScheme() . '://' . $authority, '/');
	}

	/**
	 * RFC 9728 protected-resource-metadata URL for WWW-Authenticate headers
	 * and MCP discovery responses. Points at this resource server's own
	 * well-known endpoint (scheme+host plus the app's mount prefix).
	 *
	 * Used by McpEndpointAction (WWW-Authenticate on 401/403) and
	 * McpDiscoveryAction (resourceMetadata field, OAuth-gated).
	 */
	public function protectedResourceMetadataUrl(ServerRequestInterface $request): string
	{
		return $this->baseUrl($request) . rtrim($this->config->api, '/') . '/.well-known/oauth-protected-resource';
	}

	/**
	 * Fully-qualified MCP endpoint URL derived from the inbound request,
	 * mirroring the same non-routable-host fallback used by McpDiscoveryAction.
	 */
	public function mcpEndpointUrl(ServerRequestInterface $request): string
	{
		$uri       = $request->getUri();
		$authority = $uri->getAuthority();

		return Config::isNonRoutableHost($authority) && !Config::isNonRoutableHost($this->config->domain)
			? $this->config->mcpEndpoint()
			: $this->config->mcpEndpoint($uri->getScheme() . '://' . $authority);
	}
}
