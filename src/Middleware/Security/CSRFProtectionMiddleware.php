<?php

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

/**
 * CSRF Protection Middleware.
 *
 * Validates CSRF tokens on state-changing HTTP methods (POST/PUT/DELETE/PATCH)
 * to block cross-site request forgery against session-authed users.
 *
 * **Bypasses when the request carries API key credentials** (X-API-Key header
 * or Authorization: Bearer). CSRF attacks ride session cookies that browsers
 * auto-attach; API keys aren't cookies and require explicit code to send, so
 * they're inherently safe from CSRF. Skipping the check on API-keyed requests
 * keeps external integrators working without forcing them to fetch a CSRF
 * token they don't need.
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
		private CSRFTokenManager $csrfManager,
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

		// API key auth bypass — CSRF doesn't apply to non-cookie credentials.
		if ($this->hasApiKeyCredentials($request)) {
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

		// Rotate the token after a successful state-changing request so any
		// validated token can't be replayed. Forms re-render with the fresh
		// value on the next page load via CSRFTokenManager::getToken().
		$this->csrfManager->generateToken();

		return $handler->handle($request);
	}

	/**
	 * Detect API key credentials in the request. Mirrors `ApiKeyAuthenticator`
	 * shape so we exempt the same calls that bypass session auth elsewhere.
	 */
	private function hasApiKeyCredentials(ServerRequestInterface $request): bool
	{
		if ($request->hasHeader('X-API-Key')) {
			return true;
		}

		$auth = $request->getHeaderLine('Authorization');

		return $auth !== '' && stripos($auth, 'Bearer ') === 0;
	}

	/**
	 * Validate CSRF token from the request.
	 */
	private function validateCSRFToken(ServerRequestInterface $request): bool
	{
		// Get token from various sources
		$postData  = $request->getParsedBody() ?? [];
		$headers   = $request->getHeaders();
		$queryData = $request->getQueryParams();

		// Flatten headers array for easier access
		$flatHeaders = [];
		foreach ($headers as $name => $values) {
			$flatHeaders[$name] = $values[0] ?? '';
		}

		// Convert POST data to array if it's an object
		if (is_object($postData)) {
			$postData = (array)$postData;
		}

		return $this->csrfManager->validateFromRequest($postData, $flatHeaders, $queryData);
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
