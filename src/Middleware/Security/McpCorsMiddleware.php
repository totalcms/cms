<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Support\Config;

/**
 * CORS middleware scoped to the MCP endpoint and discovery routes.
 *
 * **Default deny.** Empty `mcp.allowedOrigins` produces no CORS headers
 * at all, so a browser blocks any cross-origin request. Operators opt in
 * by adding explicit origins (e.g. `https://claude.ai`) or the `*` wildcard
 * for fully-public sites.
 *
 * **Why a dedicated middleware instead of reusing `ExternalCorsMiddleware`?**
 * The external one sets `Access-Control-Allow-Origin: *` unconditionally —
 * appropriate for API routes that already require an API key on the request
 * itself, not for the MCP surface which has a default-public persona and
 * therefore needs explicit operator opt-in for browser clients.
 *
 * **Why not per-token CORS yet?** Phase 4 (OAuth + scoped tokens) will layer
 * a per-token allowlist on top — the OAuth-authenticated request's allowed
 * origins will override the global allowlist. This middleware stays the
 * default path for unauthenticated + API-key callers.
 */
final readonly class McpCorsMiddleware implements MiddlewareInterface
{
	public function __construct(
		private Config $config,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$origin    = $request->getHeaderLine('Origin');
		$allowed   = $this->allowedOrigins();
		$echoed    = $this->resolveAllowedOrigin($origin, $allowed);

		// Preflight requests get a 204 response with the CORS handshake headers.
		// We don't dispatch the underlying handler for OPTIONS — the route is
		// intentionally short-circuited so admin auth (etc.) isn't asked of a
		// preflight that hasn't passed cookies/credentials yet.
		if (strtoupper($request->getMethod()) === 'OPTIONS') {
			return $this->preflightResponse($echoed);
		}

		$response = $handler->handle($request);

		if ($echoed !== null) {
			$response = $response
				->withHeader('Access-Control-Allow-Origin', $echoed)
				->withHeader('Vary', 'Origin');
		}

		return $response;
	}

	/**
	 * @return list<string>
	 */
	private function allowedOrigins(): array
	{
		$raw = $this->config->mcp['allowedOrigins'] ?? [];
		if (!is_array($raw)) {
			return [];
		}

		// Normalize to a list of non-empty strings — settings UI may have
		// trailing entries, duplicates, or whitespace; we tolerate all of it
		// to keep the operator from having to be perfectly clean.
		$origins = [];
		foreach ($raw as $entry) {
			if (!is_string($entry)) {
				continue;
			}
			$trim = trim($entry);
			if ($trim === '') {
				continue;
			}
			$origins[] = $trim;
		}

		return array_values(array_unique($origins));
	}

	/**
	 * Decide which value to echo in Access-Control-Allow-Origin.
	 *
	 * - empty allowlist → null (no header emitted, browser blocks).
	 * - `*` in allowlist → echo the request Origin if present, else `*`.
	 *   Echoing the origin (instead of `*`) is needed when we ever start
	 *   sending credentials, so we may as well do it consistently now.
	 * - specific allowlist → echo origin iff it matches an entry exactly.
	 *
	 * @param list<string> $allowed
	 */
	private function resolveAllowedOrigin(string $origin, array $allowed): ?string
	{
		if ($allowed === []) {
			return null;
		}

		if (in_array('*', $allowed, true)) {
			return $origin !== '' ? $origin : '*';
		}

		return in_array($origin, $allowed, true) ? $origin : null;
	}

	private function preflightResponse(?string $echoed): ResponseInterface
	{
		$response = (new Response(204));

		if ($echoed !== null) {
			$response = $response->withHeader('Access-Control-Allow-Origin', $echoed);
		}

		return $response
			->withHeader('Vary', 'Origin')
			->withHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
			->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key, Mcp-Session-Id')
			->withHeader('Access-Control-Max-Age', '86400');
	}
}
