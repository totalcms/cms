<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Computes the DNS-rebinding / Origin allowlist for the MCP Streamable HTTP
 * transport from the operator's `mcp.allowedOrigins` config.
 *
 * The SDK's StreamableHttpTransport installs a DnsRebindingProtectionMiddleware
 * with a **localhost-only** default — which would 403 every non-localhost (i.e.
 * production) request, since the `Host` header is the site's domain. We drive it
 * from config instead, mirroring McpCorsMiddleware's policy:
 *
 *  - **Open** mode (empty allowlist, or a `*` wildcard) applies no Origin
 *    restriction — any client works out of the box, exactly as the CORS layer
 *    already allows.
 *  - **Restricted** mode (explicit origins) enforces the MCP spec's
 *    403-on-invalid-Origin: the Host must be the server's own or a configured
 *    origin's, and an Origin must be one of the configured origins or the
 *    site's own. The site's own is always included so same-origin admin
 *    requests and server-to-server calls are never blocked.
 */
final class McpTransportSecurity
{
	/**
	 * Whether the MCP endpoint applies no Origin restriction (open by default).
	 *
	 * @param array<mixed> $allowedOrigins
	 */
	public static function isOpen(array $allowedOrigins): bool
	{
		foreach ($allowedOrigins as $origin) {
			if (is_string($origin) && trim($origin) === '*') {
				return true;
			}
		}

		return self::normalize($allowedOrigins) === [];
	}

	/**
	 * Host allowlist for DnsRebindingProtectionMiddleware: the server's own host
	 * plus the host of each configured origin, lowercased and de-duplicated.
	 *
	 * @param array<mixed> $allowedOrigins
	 *
	 * @return list<string>
	 */
	public static function allowedHosts(array $allowedOrigins, string $serverHost): array
	{
		$hosts      = [];
		$serverHost = strtolower(trim($serverHost));
		if ($serverHost !== '') {
			$hosts[] = $serverHost;
		}

		foreach (self::normalize($allowedOrigins) as $origin) {
			$host = parse_url($origin, PHP_URL_HOST);
			if (is_string($host) && $host !== '') {
				$hosts[] = strtolower($host);
			}
		}

		return array_values(array_unique($hosts));
	}

	/**
	 * Origin allowlist for DnsRebindingProtectionMiddleware, which from
	 * mcp/sdk 0.9 checks an Origin against full origins (scheme, host, port)
	 * rather than against the host list: the configured origins plus the
	 * site's own. The SDK accepts a same-origin request by comparing against
	 * the request URI, but that reads `http` behind a proxy that terminates
	 * TLS, so the site's own origin is listed under both schemes, as well as
	 * the one config `url` names.
	 *
	 * @param array<mixed> $allowedOrigins
	 *
	 * @return list<string>
	 */
	public static function allowedOrigins(array $allowedOrigins, string $siteUrl, ServerRequestInterface $request): array
	{
		$uri       = $request->getUri();
		$authority = $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
		$own       = $uri->getHost() !== '' ? ['https://' . $authority, 'http://' . $authority] : [];

		$origins = [];
		foreach ([$siteUrl, ...$own, ...self::normalize($allowedOrigins)] as $url) {
			$origin = self::origin($url);
			if ($origin !== null) {
				$origins[] = $origin;
			}
		}

		return array_values(array_unique($origins));
	}

	/**
	 * Origin/Host validation for callers that bypass the SDK's transport-level
	 * DnsRebindingProtectionMiddleware — currently the GET "listening stream"
	 * short-circuit in McpEndpointAction, which returns before
	 * StreamableHttpTransport (and its middleware stack) is ever constructed.
	 * Mirrors DnsRebindingProtectionMiddleware::process(): the Host must be
	 * allowed (a missing one is not), and an Origin, when sent, must be an
	 * allowed origin.
	 *
	 * @param array<mixed> $allowedOrigins
	 */
	public static function originAllowed(ServerRequestInterface $request, array $allowedOrigins, string $siteUrl): bool
	{
		if (self::isOpen($allowedOrigins)) {
			return true;
		}

		$host = $request->getHeaderLine('Host');
		$name = str_starts_with($host, '[')
			? substr($host, 0, (int)strpos($host, ']') + 1)
			: explode(':', $host, 2)[0];
		if ($name === '' || !in_array(strtolower($name), self::allowedHosts($allowedOrigins, $request->getUri()->getHost()), true)) {
			return false;
		}

		$origin = $request->getHeaderLine('Origin');

		return $origin === '' || in_array(self::origin($origin), self::allowedOrigins($allowedOrigins, $siteUrl, $request), true);
	}

	/**
	 * `scheme://host[:port]` the way a browser sends it in an Origin header:
	 * lowercase, no path or trailing slash, no default port. Null for anything
	 * that is not an absolute URL.
	 */
	private static function origin(string $url): ?string
	{
		$parts = parse_url(strtolower(trim($url)));
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return null;
		}

		$port = $parts['port'] ?? null;
		if (($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80)) {
			$port = null;
		}

		return $parts['scheme'] . '://' . $parts['host'] . ($port !== null ? ':' . $port : '');
	}

	/**
	 * @param array<mixed> $allowedOrigins
	 *
	 * @return list<string>
	 */
	private static function normalize(array $allowedOrigins): array
	{
		$out = [];
		foreach ($allowedOrigins as $origin) {
			if (!is_string($origin)) {
				continue;
			}
			$trim = trim($origin);
			if ($trim === '' || $trim === '*') {
				continue;
			}
			$out[] = $trim;
		}

		return array_values(array_unique($out));
	}
}
