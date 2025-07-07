<?php

namespace TotalCMS\Domain\Security\CSRF;

use Odan\Session\PhpSession;

/**
 * CSRF Token Manager for generating and validating anti-forgery tokens.
 *
 * Provides secure token generation, validation, and session management
 * to prevent Cross-Site Request Forgery attacks.
 */
final class CSRFTokenManager
{
	private const TOKEN_LENGTH   = 32;
	private const SESSION_KEY    = 'csrf_token';
	private const TOKEN_LIFETIME = 3600; // 1 hour

	public function __construct(
		private PhpSession $session,
	) {
	}

	/**
	 * Generate a new CSRF token and store it in the session.
	 */
	public function generateToken(): string
	{
		if (!$this->session->isStarted()) {
			throw new \RuntimeException('Session must be active to generate CSRF token');
		}

		// Generate cryptographically secure random token
		$token = bin2hex(random_bytes(self::TOKEN_LENGTH));

		// Store token with timestamp in session
		$this->session->set(self::SESSION_KEY, [
			'token'      => $token,
			'created_at' => time(),
		]);

		return $token;
	}

	/**
	 * Get the current CSRF token from session, generating if needed.
	 */
	public function getToken(): string
	{
		if (!$this->session->isStarted()) {
			throw new \RuntimeException('Session must be active to get CSRF token');
		}

		// Check if we have a valid token
		if (!$this->hasValidToken()) {
			return $this->generateToken();
		}

		$sessionData = $this->session->get(self::SESSION_KEY);

		return $sessionData['token'];
	}

	/**
	 * Validate a submitted CSRF token against the session token.
	 */
	public function validateToken(string $submittedToken): bool
	{
		if (!$this->session->isStarted()) {
			return false;
		}

		// Check if we have a session token
		if (!$this->session->has(self::SESSION_KEY)) {
			return false;
		}

		$sessionData = $this->session->get(self::SESSION_KEY);

		// Check token expiration
		if ((time() - $sessionData['created_at']) > self::TOKEN_LIFETIME) {
			$this->session->delete(self::SESSION_KEY);

			return false;
		}

		// Perform timing-safe comparison
		return hash_equals($sessionData['token'], $submittedToken);
	}

	/**
	 * Clear the CSRF token from session.
	 */
	public function clearToken(): void
	{
		if ($this->session->isStarted()) {
			$this->session->delete(self::SESSION_KEY);
		}
	}

	/**
	 * Generate CSRF token for form inclusion.
	 * Returns HTML hidden input field.
	 */
	public function getTokenField(): string
	{
		$token = $this->getToken();

		return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
	}

	/**
	 * Generate CSRF token for JavaScript/AJAX usage.
	 * Returns token data as array.
	 *
	 * @return array<string,string>
	 */
	public function getTokenForAjax(): array
	{
		return [
			'name'  => 'csrf_token',
			'value' => $this->getToken(),
		];
	}

	/**
	 * Check if session has a valid, non-expired token.
	 */
	private function hasValidToken(): bool
	{
		if (!$this->session->has(self::SESSION_KEY)) {
			return false;
		}

		$sessionData = $this->session->get(self::SESSION_KEY);

		// Check required fields
		if (!isset($sessionData['token'], $sessionData['created_at'])) {
			return false;
		}

		// Check expiration
		return (time() - $sessionData['created_at']) <= self::TOKEN_LIFETIME;
	}

	/**
	 * Regenerate CSRF token (useful after login/logout).
	 */
	public function regenerateToken(): string
	{
		$this->clearToken();

		return $this->generateToken();
	}

	/**
	 * Get token name for form fields and headers.
	 */
	public function getTokenName(): string
	{
		return 'csrf_token';
	}

	/**
	 * Validate token from various request sources.
	 * Checks POST data, headers, and query parameters.
	 *
	 * @param array<string,mixed> $postData
	 * @param array<string,string> $headers
	 * @param array<string,mixed> $queryData
	 */
	public function validateFromRequest(array $postData = [], array $headers = [], array $queryData = []): bool
	{
		$tokenName = $this->getTokenName();

		// Check POST data first (most common)
		if (!empty($postData[$tokenName])) {
			return $this->validateToken((string)$postData[$tokenName]);
		}

		// Check custom header (for AJAX requests)
		$headerName = 'X-CSRF-Token';
		if (!empty($headers[$headerName])) {
			return $this->validateToken($headers[$headerName]);
		}

		// Check query parameter (least preferred, use sparingly)
		if (!empty($queryData[$tokenName])) {
			return $this->validateToken((string)$queryData[$tokenName]);
		}

		return false;
	}
}
