<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\OperationResult;

/**
 * Centralized service for short-lived authentication tokens (password reset,
 * email verification, magic links, etc.).
 *
 * Tokens are namespaced by scope so different flows can't collide on the same
 * token string. Each (scope, email, collection) tuple has at most one active
 * token — creating a new one invalidates the previous.
 *
 * Tokens live in the cache layer with TTL, bypass dev-mode, and survive
 * customer-initiated cache clears (via CacheManager::*PasswordResetData).
 */
readonly class AuthTokenService
{
	private LoggerInterface $logger;

	public function __construct(
		private CacheManager $cacheManager,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('auth.log')->createLogger('auth-token');
	}

	/**
	 * Generate a cryptographically secure 64-character hex token.
	 */
	public function generateToken(): string
	{
		return bin2hex(random_bytes(32));
	}

	/**
	 * Create a token tied to a user (email + collection) within a scope.
	 * Invalidates any previous token for the same (scope, email, collection).
	 *
	 * @return OperationResult ['token' => string] on success
	 */
	public function createToken(string $scope, string $email, string $collection, int $ttlSeconds): OperationResult
	{
		$this->invalidatePreviousToken($scope, $email, $collection);

		$token     = $this->generateToken();
		$tokenData = [
			'email'      => $email,
			'collection' => $collection,
			'scope'      => $scope,
			'createdAt'  => time(),
			'expiresAt'  => time() + $ttlSeconds,
		];

		$stored = $this->cacheManager->storePasswordResetData(
			$this->tokenKey($scope, $token),
			$tokenData,
			$ttlSeconds,
		);

		if (!$stored) {
			$this->logger->error('Failed to store auth token', [
				'scope'      => $scope,
				'email'      => $email,
				'collection' => $collection,
			]);

			return OperationResult::failure('Unable to create token. Please try again.');
		}

		// Track the latest token per user so a new request invalidates the old one.
		$this->cacheManager->storePasswordResetData(
			$this->latestKey($scope, $email, $collection),
			['token' => $token],
			$ttlSeconds,
		);

		$this->logger->info('Auth token created', [
			'scope'      => $scope,
			'email'      => $email,
			'collection' => $collection,
			'ttl'        => $ttlSeconds,
		]);

		return OperationResult::success('Token created.', ['token' => $token]);
	}

	/**
	 * Validate a token within a scope. Returns email + collection on success.
	 *
	 * @return OperationResult ['email' => string, 'collection' => string] on success
	 */
	public function validateToken(string $scope, string $token): OperationResult
	{
		$tokenData = $this->cacheManager->getPasswordResetData($this->tokenKey($scope, $token));

		if ($tokenData === null) {
			$this->logger->warning('Auth token validation failed (not found)', [
				'scope' => $scope,
				'token' => substr($token, 0, 8) . '...',
			]);

			return OperationResult::failure('Invalid or expired token.');
		}

		// Defensive scope check — keys are scoped but a stored token should never
		// carry the wrong scope. If it does, treat as invalid.
		if (isset($tokenData['scope']) && $tokenData['scope'] !== $scope) {
			$this->logger->warning('Auth token scope mismatch', [
				'expected' => $scope,
				'actual'   => $tokenData['scope'],
			]);

			return OperationResult::failure('Invalid or expired token.');
		}

		// Double-check expiry even though cache TTL should handle it.
		if (isset($tokenData['expiresAt']) && $tokenData['expiresAt'] < time()) {
			$this->logger->warning('Auth token expired', [
				'scope'      => $scope,
				'email'      => $tokenData['email'] ?? 'unknown',
				'collection' => $tokenData['collection'] ?? 'unknown',
			]);

			$this->cacheManager->clearPasswordResetData($this->tokenKey($scope, $token));

			return OperationResult::failure('This token has expired. Please request a new one.');
		}

		return OperationResult::success('Token is valid.', [
			'email'      => $tokenData['email'],
			'collection' => $tokenData['collection'],
		]);
	}

	/**
	 * Invalidate a specific token (single-use semantics).
	 */
	public function invalidateToken(string $scope, string $token): void
	{
		$this->cacheManager->clearPasswordResetData($this->tokenKey($scope, $token));
	}

	/**
	 * Clear the "latest token" pointer for a user — call after consuming a token
	 * to fully clean up.
	 */
	public function invalidateLatest(string $scope, string $email, string $collection): void
	{
		$this->cacheManager->clearPasswordResetData($this->latestKey($scope, $email, $collection));
	}

	/**
	 * Invalidate any previous token for this (scope, email, collection).
	 */
	public function invalidatePreviousToken(string $scope, string $email, string $collection): void
	{
		$latestData = $this->cacheManager->getPasswordResetData($this->latestKey($scope, $email, $collection));

		if ($latestData !== null && isset($latestData['token'])) {
			$oldToken = (string)$latestData['token'];
			$this->cacheManager->clearPasswordResetData($this->tokenKey($scope, $oldToken));

			$this->logger->info('Previous auth token invalidated', [
				'scope'      => $scope,
				'email'      => $email,
				'collection' => $collection,
			]);
		}
	}

	private function tokenKey(string $scope, string $token): string
	{
		return "{$scope}:token:{$token}";
	}

	private function latestKey(string $scope, string $email, string $collection): string
	{
		return "{$scope}:latest:{$email}:{$collection}";
	}
}
