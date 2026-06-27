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
 * **Open by default.** An empty `mcp.allowedOrigins` is treated the same as
 * the `*` wildcard — any origin is allowed, so browser-based AI clients work
 * out of the box. Operators *restrict* access by listing specific origins
 * (e.g. `https://claude.ai`); the empty/`*` state is wide open. This is safe
 * because the middleware never sets `Access-Control-Allow-Credentials` and the
 * session cookie is `SameSite=Lax`, so a cross-origin site can't ride a
 * victim's session — the only thing an open policy exposes is the already-
 * public MCP surface. Sites with public-but-sensitive collections should list
 * explicit origins.
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
	 * - empty allowlist OR `*` in allowlist → allow any origin: echo the request
	 *   Origin if present, else `*`. Echoing the origin (instead of `*`) is
	 *   needed when we ever start sending credentials, so we do it consistently.
	 * - specific allowlist → echo origin iff it matches an entry exactly.
	 *
	 * A blank allowlist is treated the same as `*` (allow all). Operators opt
	 * OUT of wide-open browser CORS by listing specific origins, not by leaving
	 * the field empty — see the class docblock for the security trade-off.
	 *
	 * @param list<string> $allowed
	 */
	private function resolveAllowedOrigin(string $origin, array $allowed): ?string
	{
		if ($allowed === [] || in_array('*', $allowed, true)) {
			// The literal string "null" is sent by sandboxed iframes and data: URIs.
			// Echoing it back as the allowed origin grants cross-origin access to
			// those contexts, which is never the intent — treat it as absent.
			if ($origin === 'null') {
				return null;
			}

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
