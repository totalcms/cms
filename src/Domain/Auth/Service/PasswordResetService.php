<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

/**
 * Service for handling password reset functionality.
 * Token mechanics (generation, storage, expiry) are delegated to AuthTokenService;
 * this service owns the password-reset domain logic on top.
 */
readonly class PasswordResetService
{
	private const SCOPE = 'reset';

	private LoggerInterface $logger;

	public function __construct(
		private AuthTokenService $tokenService,
		private UserValidationService $userValidator,
		private ObjectUpdater $objectUpdater,
		private Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('auth.log')->createLogger('password-reset');
	}

	/**
	 * Generate a secure random token. Kept for backward compatibility; new
	 * callers should ask AuthTokenService directly.
	 */
	public function generateToken(): string
	{
		return $this->tokenService->generateToken();
	}

	/**
	 * Create a password reset token and store it in cache.
	 * Invalidates any previous tokens for the same email/collection.
	 */
	public function createResetToken(string $email, string $collection): OperationResult
	{
		// Check if user exists
		$user = $this->userValidator->findUserByEmail($email, $collection);

		if (!$user instanceof ObjectData) {
			// Don't reveal whether user exists for security
			$this->logger->warning('Password reset requested for non-existent user', [
				'email'      => $email,
				'collection' => $collection,
			]);

			// Still return success to prevent user enumeration
			return OperationResult::success('If an account exists with that email, you will receive a password reset link.');
		}

		// Get token expiry from config (in minutes)
		$expiryMinutes = (int)($this->config->auth['resetTokenExpiry'] ?? 30);
		$ttl           = $expiryMinutes * 60;

		$result = $this->tokenService->createToken(self::SCOPE, $email, $collection, $ttl);

		if (!$result->success) {
			return OperationResult::failure('Unable to create password reset token. Please try again.');
		}

		$this->logger->info('Password reset token created', [
			'email'      => $email,
			'collection' => $collection,
			'expiresIn'  => $expiryMinutes . ' minutes',
		]);

		return OperationResult::success(
			'Password reset token created successfully.',
			['token' => $result->data['token']],
		);
	}

	/**
	 * Validate a reset token and return user data if valid.
	 */
	public function validateToken(string $token): OperationResult
	{
		return $this->tokenService->validateToken(self::SCOPE, $token);
	}

	/**
	 * Reset user password using a valid token.
	 */
	public function resetPassword(string $token, string $newPassword): OperationResult
	{
		// Validate token
		$validation = $this->validateToken($token);

		if (!$validation->success) {
			return OperationResult::failure($validation->message);
		}

		// Extract email and collection (guaranteed to exist when success is true)
		$email      = (string)($validation->data['email'] ?? '');
		$collection = (string)($validation->data['collection'] ?? '');

		// Double-check these values exist (should never happen, but satisfies PHPStan)
		if ($email === '' || $collection === '') {
			return OperationResult::failure('Invalid token data.');
		}

		// Fetch user object
		$user = $this->userValidator->findUserByEmail($email, $collection);

		if (!$user instanceof ObjectData) {
			$this->logger->error('User not found during password reset', [
				'email'      => $email,
				'collection' => $collection,
			]);

			return OperationResult::failure('User account not found.');
		}

		// Hash the new password
		$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

		// Update user's password
		$userData             = $user->toArray();
		$userData['password'] = $hashedPassword;

		try {
			$this->objectUpdater->updateObject($collection, $user->id, $userData);

			// Invalidate the token (single-use)
			$this->tokenService->invalidateToken(self::SCOPE, $token);
			$this->tokenService->invalidateLatest(self::SCOPE, $email, $collection);

			$this->logger->info('Password reset successful', [
				'email'      => $email,
				'collection' => $collection,
			]);

			return OperationResult::success('Password reset successful! You can now log in with your new password.');
		} catch (\Exception $e) {
			$this->logger->error('Failed to update password', [
				'email'      => $email,
				'collection' => $collection,
				'error'      => $e->getMessage(),
			]);

			return OperationResult::failure('Failed to update password. Please try again.');
		}
	}
}
