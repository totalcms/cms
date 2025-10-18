<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ApiKey\Service;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Support\Config;

/**
 * API Key Authenticator Service.
 *
 * Centralized service for extracting and validating API keys from requests.
 * Used by both ApiKeyAuthMiddleware and DualAuthMiddleware.
 */
readonly class ApiKeyAuthenticator
{
	public function __construct(
		private ApiKeyFetcher $apiKeyFetcher,
		private Config $config,
	) {
	}

	/**
	 * Check if the request has an API key header (Authorization: Bearer or X-API-Key).
	 */
	public function hasApiKeyHeader(ServerRequestInterface $request): bool
	{
		$authHeader = $request->getHeaderLine('Authorization');

		// Check for Authorization: Bearer header
		if ($authHeader !== '' && str_starts_with($authHeader, 'Bearer ')) {
			return true;
		}

		// Check for X-API-Key header
		return $request->hasHeader('X-API-Key');
	}

	/**
	 * Authenticate request using API key from Authorization header or X-API-Key header.
	 *
	 * @return ApiKeyData|null Returns the API key data if valid, null otherwise
	 */
	public function authenticate(ServerRequestInterface $request): ?ApiKeyData
	{
		$apiKey = $this->extractApiKey($request);

		// No API key found in either header
		if ($apiKey === '') {
			return null;
		}

		// Get the route path by stripping the API base path from the full URL
		$path = $this->stripBasePath($request);

		// Validate the API key with method and path permissions
		$method = $request->getMethod();

		return $this->apiKeyFetcher->validateKey($apiKey, $method, $path);
	}

	/**
	 * Extract API key from request headers.
	 * Tries Authorization: Bearer first, then falls back to X-API-Key.
	 */
	private function extractApiKey(ServerRequestInterface $request): string
	{
		// Try Authorization: Bearer first (standard)
		$authHeader = $request->getHeaderLine('Authorization');
		if ($authHeader !== '' && str_starts_with($authHeader, 'Bearer ')) {
			return substr($authHeader, 7); // Remove "Bearer " prefix
		}

		// Fallback to X-API-Key header if no valid Bearer token (convenience)
		if ($request->hasHeader('X-API-Key')) {
			return $request->getHeaderLine('X-API-Key');
		}

		return '';
	}

	/**
	 * Strip the API base path from the request path.
	 *
	 * Config->api contains the full URL (e.g., "https://demo.totalcms.test/rw_common/plugins/stacks/tcms")
	 * We parse it to get just the path part, then strip that from the request path.
	 */
	private function stripBasePath(ServerRequestInterface $request): string
	{
		$fullPath = $request->getUri()->getPath();
		$path     = $fullPath;

		// Parse the API URL to get just the path portion
		$parsedApi = parse_url($this->config->api);
		if (isset($parsedApi['path']) && $parsedApi['path'] !== '') {
			$apiPath = rtrim($parsedApi['path'], '/');
			// Strip the API base path from the request path
			if (str_starts_with($fullPath, $apiPath)) {
				$path = substr($fullPath, strlen($apiPath));
			}
		}

		return $path;
	}
}
