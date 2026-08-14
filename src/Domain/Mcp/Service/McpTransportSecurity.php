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
 *    403-on-invalid-Origin: only the server's own host plus the configured
 *    origins are accepted. The server's own host is always included so
 *    same-origin admin requests and server-to-server calls are never blocked.
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
	 * Origin/Host validation for callers that bypass the SDK's transport-level
	 * DnsRebindingProtectionMiddleware — currently the GET "listening stream"
	 * short-circuit in McpEndpointAction, which returns before
	 * StreamableHttpTransport (and its middleware stack) is ever constructed.
	 * Mirrors DnsRebindingProtectionMiddleware::process()'s own precedence:
	 * Origin wins when present, else Host, else allow (no signal to check).
	 *
	 * @param array<mixed> $allowedOrigins
	 */
	public static function originAllowed(ServerRequestInterface $request, array $allowedOrigins): bool
	{
		if (self::isOpen($allowedOrigins)) {
			return true;
		}

		$hosts  = self::allowedHosts($allowedOrigins, $request->getUri()->getHost());
		$origin = $request->getHeaderLine('Origin');
		if ($origin !== '') {
			$host = parse_url($origin, PHP_URL_HOST);

			return is_string($host) && $host !== '' && in_array(strtolower($host), $hosts, true);
		}

		$host = $request->getHeaderLine('Host');
		if ($host === '') {
			return true;
		}

		$name = str_starts_with($host, '[')
			? substr($host, 0, (int)strpos($host, ']') + 1)
			: explode(':', $host, 2)[0];

		return in_array(strtolower($name), $hosts, true);
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
