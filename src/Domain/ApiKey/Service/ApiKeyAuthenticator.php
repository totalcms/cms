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

		return $this->onlyWhereAllowed($this->apiKeyFetcher->validateKey($apiKey, $method, $path), $request);
	}

	/**
	 * Extract API key from request headers.
	 * Tries Authorization: Bearer first, then falls back to X-API-Key.
	 */
	/**
	 * The key on $request, checked against its path but not its HTTP method:
	 * for surfaces where every call is one method whatever it does (MCP is
	 * always POST). The surface applies the key's methods per operation
	 * instead ({@see ApiKeyGrants}).
	 */
	public function authenticateIgnoringMethod(ServerRequestInterface $request): ?ApiKeyData
	{
		$apiKey = $this->extractApiKey($request);

		return $apiKey === '' ? null : $this->onlyWhereAllowed($this->apiKeyFetcher->validateKeyForPath($apiKey, $this->stripBasePath($request)), $request);
	}

	/** Headers a proxy adds when it passes a request on from elsewhere. */
	private const FORWARDING_HEADERS = ['X-Forwarded-For', 'X-Forwarded-Host', 'X-Real-IP', 'Forwarded', 'Via', 'CF-Connecting-IP'];

	/**
	 * Whether $request may use a local-only key. All of these must hold:
	 *
	 * - The server accepts local-only keys: it sets TCMS_LOCAL_KEYS=1, as
	 *   Total CMS Studio's does. A live server behind a proxy on the same
	 *   machine sees a loopback peer and often a Host of localhost, so the
	 *   request alone can't tell it apart; the server has to opt in.
	 * - No proxy forwarded it.
	 * - The socket peer is loopback AND the Host names this computer. The
	 *   peer alone passes a Studio share, where cloudflared connects from
	 *   loopback carrying the public Host; the Host alone is the caller's to
	 *   choose.
	 */
	public static function isLocalRequest(ServerRequestInterface $request): bool
	{
		$server = $request->getServerParams();
		if ((string)($server['TCMS_LOCAL_KEYS'] ?? '') !== '1') {
			return false;
		}
		foreach (self::FORWARDING_HEADERS as $header) {
			if ($request->hasHeader($header)) {
				return false;
			}
		}
		$peer = (string)($server['REMOTE_ADDR'] ?? '');
		$host = $request->getHeaderLine('Host');
		$host = strtolower($host !== '' ? $host : $request->getUri()->getHost());

		return self::isLoopback($peer) && self::namesThisComputer($host);
	}

	private static function isLoopback(string $ip): bool
	{
		if ($ip === '::1') {
			return true;
		}

		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($ip, '127.');
	}

	private static function namesThisComputer(string $host): bool
	{
		// [::1]:8780 -> ::1, shop.localhost:8780 -> shop.localhost
		if (preg_match('/^\[(.+)\](?::\d+)?$/', $host, $match) === 1) {
			$host = $match[1];
		} elseif (substr_count($host, ':') === 1) {
			$host = substr($host, 0, (int)strpos($host, ':'));
		}

		return $host === 'localhost' || str_ends_with($host, '.localhost') || self::isLoopback($host);
	}

	/** $apiKey, unless it is local-only and $request came from elsewhere. */
	private function onlyWhereAllowed(?ApiKeyData $apiKey, ServerRequestInterface $request): ?ApiKeyData
	{
		if ($apiKey instanceof ApiKeyData && $apiKey->localOnly && !self::isLocalRequest($request)) {
			return null;
		}

		return $apiKey;
	}

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
