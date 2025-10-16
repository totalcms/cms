<?php

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

/**
 * Service for managing persistent login tokens and session restoration.
 *
 * Provides secure persistent login functionality by managing tokens
 * that can restore user sessions after browser restart or session expiration.
 */
class PersistentLoginService
{
	public const PERSISTENT_COOKIE_NAME = 'tcms_persistent_token';

	private readonly string $tokenDir;

	public function __construct(
		private readonly SessionInterface $session,
		private readonly Config $config,
		private readonly UserValidationService $userValidator,
	) {
		$this->tokenDir = $this->config->tmpdir . '/persistent_tokens';
		if (!is_dir($this->tokenDir)) {
			@mkdir($this->tokenDir, 0755, true);
		}
	}

	/**
	 * Create a persistent login token and cookie for the current user.
	 */
	public function createPersistentToken(): void
	{
		$userId     = $this->session->get(SessionKeys::AUTH_USER);
		$collection = $this->session->get(SessionKeys::AUTH_COLLECTION);

		if (!$userId || !$collection) {
			return;
		}

		// Generate secure random token
		$token       = bin2hex(random_bytes(32));
		$selector    = bin2hex(random_bytes(16));
		$hashedToken = password_hash($token, PASSWORD_DEFAULT);

		// Store token data
		$tokenData = [
			'user_id'    => $userId,
			'collection' => $collection,
			'token_hash' => $hashedToken,
			'created_at' => time(),
			'expires_at' => time() + ($this->config->auth['persistentLoginDays'] * 24 * 60 * 60),
		];

		$tokenFile = $this->tokenDir . '/' . $selector . '.json';
		file_put_contents($tokenFile, json_encode($tokenData, JSON_THROW_ON_ERROR));

		// Set persistent cookie with selector:token
		$cookieValue    = $selector . ':' . $token;
		$persistentDays = $this->config->auth['persistentLoginDays'] ?? 30;
		$expiry         = time() + ($persistentDays * 24 * 60 * 60);

		$cookieParams = session_get_cookie_params();
		setcookie(
			self::PERSISTENT_COOKIE_NAME,
			$cookieValue,
			[
				'expires'  => $expiry,
				'path'     => $cookieParams['path'],
				'domain'   => $cookieParams['domain'],
				'secure'   => $cookieParams['secure'],
				'httponly' => true, // Always httponly for security
				'samesite' => $cookieParams['samesite'],
			]
		);
	}

	/**
	 * Attempt to restore session from persistent login cookie.
	 *
	 * @return bool True if session was restored, false otherwise
	 */
	public function restoreFromPersistentToken(): bool
	{
		// Don't restore if user is already logged in
		if ($this->session->has(SessionKeys::AUTH_USER)) {
			return false;
		}

		// Check for persistent cookie
		if (!isset($_COOKIE[self::PERSISTENT_COOKIE_NAME])) {
			return false;
		}

		$cookieValue = $_COOKIE[self::PERSISTENT_COOKIE_NAME];
		$parts       = explode(':', (string)$cookieValue, 2);

		if (count($parts) !== 2) {
			$this->clearPersistentCookie();

			return false;
		}

		[$selector, $token] = $parts;
		$tokenFile          = $this->tokenDir . '/' . $selector . '.json';

		// Check if token file exists
		if (!file_exists($tokenFile)) {
			$this->clearPersistentCookie();

			return false;
		}

		// Load and validate token data
		$tokenFileContents = file_get_contents($tokenFile);
		if ($tokenFileContents === false) {
			$this->clearPersistentToken($selector);

			return false;
		}
		$tokenData = json_decode($tokenFileContents, true);

		if (!$tokenData || !$this->isValidTokenData($tokenData)) {
			$this->clearPersistentToken($selector);

			return false;
		}

		// Verify token
		if (!password_verify($token, (string)$tokenData['token_hash'])) {
			$this->clearPersistentToken($selector);

			return false;
		}

		// Check if token has expired
		if (time() > $tokenData['expires_at']) {
			$this->clearPersistentToken($selector);

			return false;
		}

		// Validate user still exists and is active
		try {
			$userExists = $this->userValidator->validateUserById(
				$tokenData['user_id'],
				$tokenData['collection']
			);

			if ($userExists === []) {
				$this->clearPersistentToken($selector);

				return false;
			}
		} catch (\Throwable) {
			$this->clearPersistentToken($selector);

			return false;
		}

		// Restore session
		$this->session->set(SessionKeys::AUTH_USER, $tokenData['user_id']);
		$this->session->set(SessionKeys::AUTH_COLLECTION, $tokenData['collection']);
		$this->session->set(SessionKeys::AUTH_PERSISTENT_LOGIN, true);
		$this->session->set(SessionKeys::LAST_ACTIVITY, time());

		// Generate new token for security (token rotation)
		$this->clearPersistentToken($selector);
		$this->createPersistentToken();

		return true;
	}

	/**
	 * Clear persistent login token and cookie.
	 */
	public function clearPersistentLogin(): void
	{
		// Clear cookie
		$this->clearPersistentCookie();

		// Try to clear token file if we can identify it
		if (isset($_COOKIE[self::PERSISTENT_COOKIE_NAME])) {
			$parts = explode(':', (string)$_COOKIE[self::PERSISTENT_COOKIE_NAME], 2);
			if (count($parts) === 2) {
				$this->clearPersistentToken($parts[0]);
			}
		}
	}

	/**
	 * Check if current session has persistent login enabled.
	 */
	public function hasPersistentLogin(): bool
	{
		return $this->session->get(SessionKeys::AUTH_PERSISTENT_LOGIN, false) === true;
	}

	/**
	 * Clean up expired tokens (should be called periodically).
	 */
	public function cleanupExpiredTokens(): void
	{
		$now   = time();
		$files = glob($this->tokenDir . '/*.json') ?: [];

		foreach ($files as $file) {
			$fileContents = file_get_contents($file);
			if ($fileContents === false) {
				continue;
			}
			$tokenData = json_decode($fileContents, true);
			if ($tokenData && isset($tokenData['expires_at']) && $now > $tokenData['expires_at']) {
				unlink($file);
			}
		}
	}

	/**
	 * Validate token data structure.
	 *
	 * @param array<string,mixed> $tokenData
	 */
	private function isValidTokenData(array $tokenData): bool
	{
		$requiredKeys = ['user_id', 'collection', 'token_hash', 'created_at', 'expires_at'];

		foreach ($requiredKeys as $key) {
			if (!isset($tokenData[$key])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Clear persistent cookie.
	 */
	private function clearPersistentCookie(): void
	{
		if (isset($_COOKIE[self::PERSISTENT_COOKIE_NAME])) {
			$cookieParams = session_get_cookie_params();
			setcookie(
				self::PERSISTENT_COOKIE_NAME,
				'',
				[
					'expires'  => time() - 3600,
					'path'     => $cookieParams['path'],
					'domain'   => $cookieParams['domain'],
					'secure'   => $cookieParams['secure'],
					'httponly' => true,
					'samesite' => $cookieParams['samesite'],
				]
			);
		}
	}

	/**
	 * Clear persistent token file.
	 */
	private function clearPersistentToken(string $selector): void
	{
		$tokenFile = $this->tokenDir . '/' . $selector . '.json';
		if (file_exists($tokenFile)) {
			unlink($tokenFile);
		}
	}
}
