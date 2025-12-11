<?php

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
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
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly SessionInterface $session,
		private readonly Config $config,
		private readonly UserValidationService $userValidator,
		LoggerFactory $loggerFactory,
	) {
		$this->tokenDir = $this->config->tmpdir . '/persistent_tokens';
		if (!is_dir($this->tokenDir)) {
			@mkdir($this->tokenDir, 0755, true);
		}
		$this->logger = $loggerFactory->addFileHandler('totalcms-access.log')->createLogger('persistent-login');
	}

	/**
	 * Create a persistent login token and cookie for the current user.
	 *
	 * @return string|null The selector of the created token, or null on failure
	 */
	public function createPersistentToken(): ?string
	{
		$userId     = $this->session->get(SessionKeys::AUTH_USER);
		$collection = $this->session->get(SessionKeys::AUTH_COLLECTION);

		if (!$userId || !$collection) {
			$this->logger->debug('Cannot create persistent token: no user or collection in session');

			return null;
		}

		// Check if headers have been sent (cookie would fail)
		if (headers_sent($file, $line)) {
			$this->logger->warning("Cannot create persistent token: headers already sent at $file:$line");

			return null;
		}

		// Generate secure random token
		$token       = bin2hex(random_bytes(32));
		$selector    = bin2hex(random_bytes(16));
		$hashedToken = password_hash($token, PASSWORD_DEFAULT);

		$persistentDays = $this->config->auth['persistentLoginDays'] ?? 30;
		$expiry         = time() + ($persistentDays * 24 * 60 * 60);

		// Store token data
		$tokenData = [
			'user_id'    => $userId,
			'collection' => $collection,
			'token_hash' => $hashedToken,
			'created_at' => time(),
			'expires_at' => $expiry,
		];

		$tokenFile = $this->tokenDir . '/' . $selector . '.json';

		try {
			$written = file_put_contents($tokenFile, json_encode($tokenData, JSON_THROW_ON_ERROR));
			if ($written === false) {
				$this->logger->error('Failed to write persistent token file', ['file' => $tokenFile]);

				return null;
			}
		} catch (\JsonException $e) {
			$this->logger->error('Failed to encode persistent token data', ['error' => $e->getMessage()]);

			return null;
		}

		// Set persistent cookie with selector:token
		$cookieValue  = $selector . ':' . $token;
		$cookieParams = session_get_cookie_params();

		$cookieSet = setcookie(
			self::PERSISTENT_COOKIE_NAME,
			$cookieValue,
			[
				'expires'  => $expiry,
				'path'     => $cookieParams['path'],
				'domain'   => $cookieParams['domain'],
				'secure'   => $cookieParams['secure'],
				'httponly' => true,
				'samesite' => $cookieParams['samesite'],
			]
		);

		if (!$cookieSet) {
			// Cookie failed - clean up the token file we just created
			$this->logger->error('Failed to set persistent cookie, cleaning up token file');
			@unlink($tokenFile);

			return null;
		}

		$this->logger->info('Created persistent login token', ['user' => $userId, 'selector' => $selector]);

		return $selector;
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
		if (!$this->hasPersistentCookie()) {
			return false;
		}

		$cookieValue = $_COOKIE[self::PERSISTENT_COOKIE_NAME];
		$parts       = explode(':', (string)$cookieValue, 2);

		if (count($parts) !== 2) {
			$this->logger->debug('Invalid persistent cookie format');
			$this->clearPersistentCookie();

			return false;
		}

		[$selector, $token] = $parts;
		$tokenFile          = $this->tokenDir . '/' . $selector . '.json';

		// Check if token file exists
		if (!file_exists($tokenFile)) {
			$this->logger->debug('Persistent token file not found', ['selector' => $selector]);
			$this->clearPersistentCookie();

			return false;
		}

		// Load and validate token data
		$tokenFileContents = file_get_contents($tokenFile);
		if ($tokenFileContents === false) {
			$this->logger->warning('Failed to read persistent token file', ['selector' => $selector]);
			$this->clearPersistentToken($selector);

			return false;
		}
		$tokenData = json_decode($tokenFileContents, true);

		if (!$tokenData || !$this->isValidTokenData($tokenData)) {
			$this->logger->warning('Invalid persistent token data structure', ['selector' => $selector]);
			$this->clearPersistentToken($selector);

			return false;
		}

		// Verify token hash
		if (!password_verify($token, (string)$tokenData['token_hash'])) {
			$this->logger->warning('Persistent token verification failed', ['selector' => $selector]);
			$this->clearPersistentToken($selector);

			return false;
		}

		// Check if token has expired
		if (time() > $tokenData['expires_at']) {
			$this->logger->debug('Persistent token expired', ['selector' => $selector]);
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
				$this->logger->warning('User no longer exists for persistent token', [
					'selector' => $selector,
					'user_id'  => $tokenData['user_id'],
				]);
				$this->clearPersistentToken($selector);

				return false;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('User validation failed for persistent token', [
				'selector' => $selector,
				'user_id'  => $tokenData['user_id'],
				'error'    => $e->getMessage(),
			]);
			$this->clearPersistentToken($selector);

			return false;
		}

		// Restore session FIRST
		$this->session->set(SessionKeys::AUTH_USER, $tokenData['user_id']);
		$this->session->set(SessionKeys::AUTH_COLLECTION, $tokenData['collection']);
		$this->session->set(SessionKeys::AUTH_PERSISTENT_LOGIN, true);
		$this->session->set(SessionKeys::LAST_ACTIVITY, time());

		$this->logger->info('Restored session from persistent token', [
			'user_id'  => $tokenData['user_id'],
			'selector' => $selector,
		]);

		// Token rotation: Create new token FIRST, only delete old if successful
		$newSelector = $this->createPersistentToken();

		if ($newSelector !== null) {
			// New token created successfully, safe to delete old one
			$this->clearPersistentTokenFile($selector);
			$this->logger->debug('Token rotation complete', [
				'old_selector' => $selector,
				'new_selector' => $newSelector,
			]);
		} else {
			// New token failed - keep old token active (don't delete it)
			// User remains logged in, but next restoration will use same token
			$this->logger->warning('Token rotation failed, keeping old token', ['selector' => $selector]);
		}

		return true;
	}

	/**
	 * Clear persistent login token and cookie.
	 */
	public function clearPersistentLogin(): void
	{
		// Try to clear token file if we can identify it from cookie
		if ($this->hasPersistentCookie()) {
			$parts = explode(':', (string)$_COOKIE[self::PERSISTENT_COOKIE_NAME], 2);
			if (count($parts) === 2) {
				$this->clearPersistentTokenFile($parts[0]);
			}
		}

		// Clear cookie
		$this->clearPersistentCookie();

		$this->logger->debug('Cleared persistent login');
	}

	/**
	 * Check if current session has persistent login enabled.
	 *
	 * Note: This checks the SESSION flag. Use hasPersistentCookie() to check for the cookie.
	 */
	public function hasPersistentLogin(): bool
	{
		return $this->session->get(SessionKeys::AUTH_PERSISTENT_LOGIN, false) === true;
	}

	/**
	 * Check if a persistent login cookie exists.
	 *
	 * This checks the actual cookie from the request, independent of session state.
	 * Use this to determine if restoration should be attempted.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function hasPersistentCookie(): bool
	{
		return isset($_COOKIE[self::PERSISTENT_COOKIE_NAME])
			&& $_COOKIE[self::PERSISTENT_COOKIE_NAME] !== '';
	}

	/**
	 * Check if user has persistent login (either by session flag OR cookie).
	 *
	 * This is useful for middleware to determine if a user should be considered
	 * for persistent login behavior even when their session has expired.
	 */
	public function hasPersistentLoginOrCookie(): bool
	{
		return $this->hasPersistentLogin() || $this->hasPersistentCookie();
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
	 * Clear persistent token file only (does not clear cookie).
	 */
	private function clearPersistentTokenFile(string $selector): void
	{
		$tokenFile = $this->tokenDir . '/' . $selector . '.json';
		if (file_exists($tokenFile)) {
			@unlink($tokenFile);
		}
	}

	/**
	 * Clear persistent token file AND cookie.
	 */
	private function clearPersistentToken(string $selector): void
	{
		$this->clearPersistentTokenFile($selector);
		$this->clearPersistentCookie();
	}
}
