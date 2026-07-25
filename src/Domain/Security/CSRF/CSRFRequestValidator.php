<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\CSRF;

use Psr\Http\Message\ServerRequestInterface;

/**
 * PSR-7 adapter for CSRF token validation: extracts the token from the
 * request (POST body field, X-CSRF-Token header, or query param) and
 * validates it against the session token. Shared by
 * CSRFProtectionMiddleware (admin routes) and the auth middlewares'
 * session-auth enforcement on the API routes.
 */
readonly class CSRFRequestValidator
{
	/**
	 * HTTP methods that require CSRF protection. Everything else is
	 * read-only and exempt.
	 */
	public const PROTECTED_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

	public function __construct(
		private CSRFTokenManager $csrfManager,
	) {
	}

	/**
	 * Whether the request method is state-changing and therefore subject
	 * to CSRF validation.
	 */
	public function methodRequiresValidation(ServerRequestInterface $request): bool
	{
		return in_array($request->getMethod(), self::PROTECTED_METHODS, true);
	}

	/**
	 * Validate the CSRF token carried by the request.
	 */
	public function validate(ServerRequestInterface $request): bool
	{
		// Get token from various sources
		$postData  = $request->getParsedBody() ?? [];
		$queryData = $request->getQueryParams();

		// Convert POST data to array if it's an object
		if (is_object($postData)) {
			$postData = (array)$postData;
		}

		// Use getHeader() for the X-CSRF-Token lookup. PSR-7's getHeader()
		// is case-insensitive and returns an array of values, while iterating
		// getHeaders() and doing an exact-case array lookup misses when the
		// underlying transport normalises header names (HTTP/2 lowercases by
		// spec; some PSR-7 implementations also normalise). Taking only the
		// first value mirrors the previous behaviour and avoids the comma-
		// joined surprise of getHeaderLine() when a client sends duplicates.
		$flatHeaders = [];
		$tokenValues = $request->getHeader('X-CSRF-Token');
		if (isset($tokenValues[0]) && $tokenValues[0] !== '') {
			$flatHeaders['X-CSRF-Token'] = $tokenValues[0];
		}

		return $this->csrfManager->validateFromRequest($postData, $flatHeaders, $queryData);
	}
}
