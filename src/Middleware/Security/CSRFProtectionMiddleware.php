<?php

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;

/**
 * CSRF Protection Middleware.
 *
 * Validates CSRF tokens on state-changing HTTP methods (POST/PUT/DELETE/PATCH)
 * to block cross-site request forgery against session-authed users.
 *
 * **Bypasses when the request carries a VALID API key** (X-API-Key header or
 * Authorization: Bearer). CSRF attacks ride session cookies that browsers
 * auto-attach; API keys aren't cookies and require explicit code to send, so
 * they're inherently safe from CSRF. Skipping the check on API-keyed requests
 * keeps external integrators working without forcing them to fetch a CSRF
 * token they don't need.
 *
 * IMPORTANT: The bypass requires the key to actually validate against the
 * stored key store. A request that presents an unrecognised or malformed key
 * header is rejected with 403 rather than silently falling through to
 * session-cookie authentication. This closes the vector where an
 * attacker-controlled page could send `X-API-Key: anything` (or an arbitrary
 * Bearer value) in a cross-origin fetch to skip CSRF and let the victim's
 * session cookie do the rest.
 *
 * Operator forms get the token automatically: TotalForm / SimpleForm /
 * LoginForm render `<input type="hidden" name="csrf_token" ...>` via
 * CSRFTokenManager::getTokenField(), and the admin JS reads
 * `<meta name="csrf-token">` and injects it as `X-CSRF-Token` on every
 * HTMX request.
 */
readonly class CSRFProtectionMiddleware implements MiddlewareInterface
{
	/**
	 * HTTP methods that require CSRF protection.
	 */
	private const PROTECTED_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

	/**
	 * Routes that should be exempt from CSRF protection
	 * (e.g., API endpoints with other authentication mechanisms).
	 */
	private const EXEMPT_ROUTES = [
		// Add specific route patterns here if needed
		// '/api/webhook/*',
	];

	public function __construct(
		private ApiKeyAuthenticator $apiKeyAuthenticator,
		private \TotalCMS\Domain\Security\CSRF\CSRFRequestValidator $csrfValidator,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$method = $request->getMethod();
		$uri    = $request->getUri()->getPath();

		// Only protect state-changing methods
		if (!in_array($method, self::PROTECTED_METHODS)) {
			return $handler->handle($request);
		}

		// If the request carries an API key header, validate it. A valid key
		// means non-cookie auth — CSRF doesn't apply. An invalid key is
		// rejected outright (403) so attackers cannot use a bogus header to
		// bypass CSRF and ride the session cookie instead.
		if ($this->apiKeyAuthenticator->hasApiKeyHeader($request)) {
			$validatedKey = $this->apiKeyAuthenticator->authenticate($request);
			if (!$validatedKey instanceof \TotalCMS\Domain\ApiKey\Data\ApiKeyData) {
				throw new HttpForbiddenException(
					$request,
					'Invalid API key. Request rejected.'
				);
			}

			// Valid API key — CSRF is not required for non-cookie credentials.
			return $handler->handle($request);
		}

		// Check if route is explicitly exempt
		if ($this->isExemptRoute($uri)) {
			return $handler->handle($request);
		}

		// Validate CSRF token
		if (!$this->validateCSRFToken($request)) {
			throw new HttpForbiddenException(
				$request,
				'CSRF token validation failed. This request appears to be a cross-site request forgery.'
			);
		}

		// Token is session-bound, not per-request. Rotation here would break
		// AJAX/HTMX flows that stay on the page after a state change (the
		// meta tag in DOM still carries the old value). Per-session tokens
		// are the industry standard (Django, Rails, Laravel, Symfony, ASP.NET
		// Core) and OWASP's CSRF cheatsheet lists per-request rotation as
		// "MAY" not "MUST". CSRFTokenManager::regenerateToken() is still
		// available for session-boundary events (login, password change).

		return $handler->handle($request);
	}

	/**
	 * Validate CSRF token from the request.
	 */
	private function validateCSRFToken(ServerRequestInterface $request): bool
	{
		return $this->csrfValidator->validate($request);
	}

	/**
	 * Check if a route is exempt from CSRF protection.
	 */
	private function isExemptRoute(string $uri): bool
	{
		/** @phpstan-ignore-next-line */
		foreach (self::EXEMPT_ROUTES as $exemptPattern) {
			// Simple wildcard matching for now
			$pattern = str_replace('*', '.*', preg_quote($exemptPattern, '/'));
			if (preg_match('/^' . $pattern . '$/', $uri)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if request method requires CSRF protection.
	 */
	public function requiresProtection(string $method): bool
	{
		return in_array(strtoupper($method), self::PROTECTED_METHODS);
	}
}
