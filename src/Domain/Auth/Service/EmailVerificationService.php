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
 * Service for handling email verification of new user accounts.
 *
 * When a collection has `requireEmailVerification` enabled, new public
 * registrations are created with `active = false`. This service issues a
 * verification token sent to the user's email; clicking the link activates
 * the account.
 *
 * Token mechanics (generation, storage, expiry, single-use) are delegated to
 * AuthTokenService under the 'verify' scope.
 */
readonly class EmailVerificationService
{
	private const SCOPE = 'verify';

	private LoggerInterface $logger;

	public function __construct(
		private AuthTokenService $tokenService,
		private UserValidationService $userValidator,
		private ObjectUpdater $objectUpdater,
		private Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('auth.log')->createLogger('email-verification');
	}

	/**
	 * Create an email verification token for a user.
	 * Invalidates any previous verification token for the same email/collection.
	 *
	 * @return OperationResult ['token' => string] on success
	 */
	public function createVerificationToken(string $email, string $collection): OperationResult
	{
		// Check that the user exists. Unlike password reset, we don't need to
		// hide existence here — this is called immediately after we just created
		// the account, so the caller already knows the user is real.
		$user = $this->userValidator->findUserByEmail($email, $collection);

		if (!$user instanceof ObjectData) {
			$this->logger->warning('Email verification token requested for non-existent user', [
				'email'      => $email,
				'collection' => $collection,
			]);

			return OperationResult::failure('User not found.');
		}

		// Verification links live longer than password resets — users may not
		// check their email for a day or two.
		$expiryMinutes = (int)($this->config->auth['verificationTokenExpiry'] ?? 1440);
		$ttl           = $expiryMinutes * 60;

		$result = $this->tokenService->createToken(self::SCOPE, $email, $collection, $ttl);

		if (!$result->success) {
			return OperationResult::failure('Unable to create verification token. Please try again.');
		}

		$this->logger->info('Email verification token created', [
			'email'      => $email,
			'collection' => $collection,
			'expiresIn'  => $expiryMinutes . ' minutes',
		]);

		return OperationResult::success(
			'Verification token created successfully.',
			['token' => $result->data['token']],
		);
	}

	/**
	 * Issue a fresh verification token for an existing inactive user.
	 * Used when the original token expired or the user lost the email.
	 *
	 * Returns a generic success message regardless of whether the user exists
	 * or is already active — same anti-enumeration posture as forgot-password.
	 *
	 * @return OperationResult ['token' => string] on success when the user
	 *                         exists AND is inactive; ['token' => null] when
	 *                         the request is silently dropped
	 */
	public function resendVerificationToken(string $email, string $collection): OperationResult
	{
		$user = $this->userValidator->findUserByEmail($email, $collection);

		if (!$user instanceof ObjectData) {
			$this->logger->info('Resend verification requested for non-existent user', [
				'email'      => $email,
				'collection' => $collection,
			]);

			// Generic success — don't reveal whether the user exists.
			return OperationResult::success('If an account exists with that email and is not yet verified, a new verification link will be sent.');
		}

		$userData = $user->toArray();
		if (isset($userData['active']) && $userData['active'] === true) {
			$this->logger->info('Resend verification requested for already-active user', [
				'email'      => $email,
				'collection' => $collection,
			]);

			// Generic success — don't reveal account state to the caller.
			return OperationResult::success('If an account exists with that email and is not yet verified, a new verification link will be sent.');
		}

		// Account is inactive — issue a fresh token. AuthTokenService's
		// invalidatePreviousToken (called inside createToken) clears any
		// outstanding token first, so the user can only act on the latest link.
		$expiryMinutes = (int)($this->config->auth['verificationTokenExpiry'] ?? 1440);
		$ttl           = $expiryMinutes * 60;

		$result = $this->tokenService->createToken(self::SCOPE, $email, $collection, $ttl);

		if (!$result->success) {
			// Don't surface the failure — same anti-enumeration message.
			return OperationResult::success('If an account exists with that email and is not yet verified, a new verification link will be sent.');
		}

		$this->logger->info('Verification token re-issued', [
			'email'      => $email,
			'collection' => $collection,
			'expiresIn'  => $expiryMinutes . ' minutes',
		]);

		return OperationResult::success(
			'A new verification link has been sent.',
			['token' => $result->data['token']],
		);
	}

	/**
	 * Validate a verification token. Returns email + collection on success.
	 */
	public function validateToken(string $token): OperationResult
	{
		return $this->tokenService->validateToken(self::SCOPE, $token);
	}

	/**
	 * Activate a user account using a valid verification token.
	 * Sets `active = true` on the user record and invalidates the token.
	 *
	 * @return OperationResult ['email' => string, 'collection' => string, 'userId' => string] on success
	 */
	public function activateUser(string $token): OperationResult
	{
		$validation = $this->validateToken($token);

		if (!$validation->success) {
			return OperationResult::failure($validation->message);
		}

		$email      = (string)($validation->data['email'] ?? '');
		$collection = (string)($validation->data['collection'] ?? '');

		if ($email === '' || $collection === '') {
			return OperationResult::failure('Invalid token data.');
		}

		$user = $this->userValidator->findUserByEmail($email, $collection);

		if (!$user instanceof ObjectData) {
			$this->logger->error('User not found during email verification', [
				'email'      => $email,
				'collection' => $collection,
			]);

			return OperationResult::failure('User account not found.');
		}

		$userData           = $user->toArray();
		$userData['active'] = true;

		try {
			$this->objectUpdater->updateObject($collection, $user->id, $userData);

			// Single-use: invalidate the token + latest pointer
			$this->tokenService->invalidateToken(self::SCOPE, $token);
			$this->tokenService->invalidateLatest(self::SCOPE, $email, $collection);

			$this->logger->info('User account activated via email verification', [
				'email'      => $email,
				'collection' => $collection,
				'userId'     => $user->id,
			]);

			return OperationResult::success('Your account has been verified.', [
				'email'      => $email,
				'collection' => $collection,
				'userId'     => $user->id,
			]);
		} catch (\Exception $e) {
			$this->logger->error('Failed to activate user account', [
				'email'      => $email,
				'collection' => $collection,
				'error'      => $e->getMessage(),
			]);

			return OperationResult::failure('Failed to activate account. Please try again.');
		}
	}
}
