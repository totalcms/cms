<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Service for handling password reset functionality.
 * Manages token generation, validation, and password updates.
 */
readonly class PasswordResetService
{
	private LoggerInterface $logger;

	public function __construct(
		private CacheManager $cacheManager,
		private IndexSearcher $indexSearcher,
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
		private Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('auth.log')->createLogger('password-reset');
	}

	/**
	 * Generate a secure random token for password reset.
	 */
	public function generateToken(): string
	{
		return bin2hex(random_bytes(32)); // 64 character hex string
	}

	/**
	 * Find a user by email address in the specified collection.
	 */
	private function findUserByEmail(string $email, string $collection): ?ObjectData
	{
		try {
			$users = $this->indexSearcher->searchByProperty($collection, 'email', $email);
			$first = $users->first();

			if ($users->isEmpty() || is_null($first)) {
				return null;
			}

			return $this->objectFetcher->fetchObject($collection, $first['id']);
		} catch (\Exception) {
			return null;
		}
	}

	/**
	 * Create a password reset token and store it in cache.
	 * Invalidates any previous tokens for the same email/collection.
	 *
	 * @return array{success:bool,token?:string,message:string}
	 */
	public function createResetToken(string $email, string $collection): array
	{
		// Check if user exists
		$user = $this->findUserByEmail($email, $collection);

		if (!$user instanceof ObjectData) {
			// Don't reveal whether user exists for security
			$this->logger->warning('Password reset requested for non-existent user', [
				'email'      => $email,
				'collection' => $collection,
			]);

			return [
				'success' => true, // Still return success to prevent user enumeration
				'message' => 'If an account exists with that email, you will receive a password reset link.',
			];
		}

		// Invalidate any previous token for this user
		$this->invalidatePreviousToken($email, $collection);

		// Generate new token
		$token = $this->generateToken();

		// Get token expiry from config (in minutes)
		$expiryMinutes = (int)($this->config->auth['resetTokenExpiry'] ?? 30);
		$ttl           = $expiryMinutes * 60; // Convert to seconds

		// Store token data in cache
		$tokenData = [
			'email'      => $email,
			'collection' => $collection,
			'createdAt'  => time(),
			'expiresAt'  => time() + $ttl,
		];

		$stored = $this->cacheManager->storePasswordResetData("token:{$token}", $tokenData, $ttl);

		if (!$stored) {
			$this->logger->error('Failed to store password reset token', [
				'email'      => $email,
				'collection' => $collection,
			]);

			return [
				'success' => false,
				'message' => 'Unable to create password reset token. Please try again.',
			];
		}

		// Store reference to latest token for this user
		$latestTokenData = ['token' => $token];
		$this->cacheManager->storePasswordResetData("latest:{$email}:{$collection}", $latestTokenData, $ttl);

		$this->logger->info('Password reset token created', [
			'email'      => $email,
			'collection' => $collection,
			'expiresIn'  => $expiryMinutes . ' minutes',
		]);

		return [
			'success' => true,
			'token'   => $token,
			'message' => 'Password reset token created successfully.',
		];
	}

	/**
	 * Invalidate any previous reset token for a user.
	 */
	private function invalidatePreviousToken(string $email, string $collection): void
	{
		$latestData = $this->cacheManager->getPasswordResetData("latest:{$email}:{$collection}");

		if ($latestData !== null && isset($latestData['token'])) {
			$oldToken = $latestData['token'];
			$this->cacheManager->clearPasswordResetData("token:{$oldToken}");

			$this->logger->info('Previous password reset token invalidated', [
				'email'      => $email,
				'collection' => $collection,
			]);
		}
	}

	/**
	 * Validate a reset token and return user data if valid.
	 *
	 * @return array{valid:bool,email?:string,collection?:string,message:string}
	 */
	public function validateToken(string $token): array
	{
		$tokenData = $this->cacheManager->getPasswordResetData("token:{$token}");

		if ($tokenData === null) {
			$this->logger->warning('Password reset attempted with invalid token', [
				'token' => substr($token, 0, 8) . '...', // Log only first 8 chars for security
			]);

			return [
				'valid'   => false,
				'message' => 'Invalid or expired reset token.',
			];
		}

		// Check if token has expired (double-check even though cache should handle TTL)
		if (isset($tokenData['expiresAt']) && $tokenData['expiresAt'] < time()) {
			$this->logger->warning('Password reset attempted with expired token', [
				'email'      => $tokenData['email'] ?? 'unknown',
				'collection' => $tokenData['collection'] ?? 'unknown',
			]);

			// Clean up expired token
			$this->cacheManager->clearPasswordResetData("token:{$token}");

			return [
				'valid'   => false,
				'message' => 'This reset token has expired. Please request a new one.',
			];
		}

		return [
			'valid'      => true,
			'email'      => $tokenData['email'],
			'collection' => $tokenData['collection'],
			'message'    => 'Token is valid.',
		];
	}

	/**
	 * Reset user password using a valid token.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function resetPassword(string $token, string $newPassword): array
	{
		// Validate token
		$validation = $this->validateToken($token);

		if (!$validation['valid']) {
			return [
				'success' => false,
				'message' => $validation['message'],
			];
		}

		// Extract email and collection (guaranteed to exist when valid is true)
		$email      = $validation['email'] ?? '';
		$collection = $validation['collection'] ?? '';

		// Double-check these values exist (should never happen, but satisfies PHPStan)
		if ($email === '' || $collection === '') {
			return [
				'success' => false,
				'message' => 'Invalid token data.',
			];
		}

		// Fetch user object
		$user = $this->findUserByEmail($email, $collection);

		if (!$user instanceof ObjectData) {
			$this->logger->error('User not found during password reset', [
				'email'      => $email,
				'collection' => $collection,
			]);

			return [
				'success' => false,
				'message' => 'User account not found.',
			];
		}

		// Hash the new password
		$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

		// Update user's password
		$userData             = $user->toArray();
		$userData['password'] = $hashedPassword;

		try {
			$this->objectUpdater->updateObject($collection, $user->id, $userData);

			// Invalidate the token (single-use)
			$this->cacheManager->clearPasswordResetData("token:{$token}");
			$this->cacheManager->clearPasswordResetData("latest:{$email}:{$collection}");

			$this->logger->info('Password reset successful', [
				'email'      => $email,
				'collection' => $collection,
			]);

			return [
				'success' => true,
				'message' => 'Password reset successful! You can now log in with your new password.',
			];
		} catch (\Exception $e) {
			$this->logger->error('Failed to update password', [
				'email'      => $email,
				'collection' => $collection,
				'error'      => $e->getMessage(),
			]);

			return [
				'success' => false,
				'message' => 'Failed to update password. Please try again.',
			];
		}
	}
}
