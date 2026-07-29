<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\CSRF;

use Psr\Http\Message\ServerRequestInterface;

/**
 * PSR-7 adapter for CSRF validation, and the single place the CSRF policy
 * lives. Shared by CSRFProtectionMiddleware (admin routes), the auth
 * middlewares' session-auth enforcement on the API routes, and
 * ExtensionRouteAction's in-handler session check.
 *
 * The policy is `passes()`: a state-changing request is accepted on EITHER a
 * browser-verified same origin OR a valid CSRF token. See that method for why
 * the origin check is equivalent protection and why it comes first.
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
		private RequestOriginValidator $originValidator,
	) {
	}

	/**
	 * Whether a request clears CSRF protection.
	 *
	 * Read-only methods pass untouched. For state-changing methods:
	 *
	 *   - **Same origin** → pass. The browser stamps Origin and script cannot
	 *     forge it, so this proves what a token proves, without the client
	 *     having to send anything. This is what lets Dropzone uploads, Tiptap
	 *     XHRs and third-party extension JS work without being taught about
	 *     tokens — the omission that broke uploads in 3.5.0-rc.13.
	 *   - **Cross origin** → fail, even with a valid token. A token that leaks
	 *     (log, referrer, shoulder-surf) must not be replayable from elsewhere.
	 *   - **Unknown** → fall back to the token. No browser headers means a
	 *     non-browser caller, which has no CSRF surface, but we ask for proof
	 *     rather than assume.
	 */
	public function passes(ServerRequestInterface $request): bool
	{
		if (!$this->methodRequiresValidation($request)) {
			return true;
		}

		return match ($this->originValidator->verdict($request)) {
			OriginVerdict::SameOrigin  => true,
			OriginVerdict::CrossOrigin => false,
			OriginVerdict::Unknown     => $this->validate($request),
		};
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
