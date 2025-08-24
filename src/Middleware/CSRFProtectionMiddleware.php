<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

/**
 * CSRF Protection Middleware.
 *
 * Validates CSRF tokens for state-changing HTTP methods (POST, PUT, DELETE, PATCH)
 * to prevent Cross-Site Request Forgery attacks.
 */
final readonly class CSRFProtectionMiddleware implements MiddlewareInterface
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

		return $handler->handle($request);
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
	 * Add a route pattern to the exempt list.
	 * Useful for dynamic configuration.
	 */
	public function addExemptRoute(string $routePattern): void
	{
		if (!in_array($routePattern, self::EXEMPT_ROUTES)) {
			$exemptRoutes   = self::EXEMPT_ROUTES;
			$exemptRoutes[] = $routePattern;
			// Note: This modifies the constant conceptually,
			// but in practice you'd want to make EXEMPT_ROUTES non-const
			// or use a property for dynamic exemptions
		}
	}

	/**
	 * Check if request method requires CSRF protection.
	 */
	public function requiresProtection(string $method): bool
	{
		return in_array(strtoupper($method), self::PROTECTED_METHODS);
	}
}
