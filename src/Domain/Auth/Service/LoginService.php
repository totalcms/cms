<?php

namespace TotalCMS\Domain\Auth\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Payload\UserEventPayload;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

class LoginService
{
	public const ACCESS_LOG = 'access.log';

	private readonly LoggerInterface $logger;
	private string $account = '';

	public function __construct(
		private readonly UserValidationService $validator,
		private readonly LastLoginUpdateService $updateService,
		private readonly FirstLoginChecker $firstLoginChecker,
		private readonly LoggerFactory $loggerFactory,
		private readonly Config $config,
		private readonly EventDispatcher $eventDispatcher,
	) {
		$this->logger = $this->loggerFactory->addFileHandler(self::ACCESS_LOG)->createLogger('login');
	}

	/** @return array<string,mixed> */
	public function authenticate(string $email, string $password, string $collection = ''): array
	{
		if ($this->firstLoginChecker->isNewInstallation()) {
			$this->logger->info('First login detected, creating first user');
			$this->firstLoginChecker->createFirstUser($email, $password);
			// for first login, force the user to login to the default auth collection
			$collection = $this->config->auth['collection'];
		}

		$defaultCollection = $this->config->auth['collection'];

		// SuperAdmin Authentication: Check if this user is a SuperAdmin in the default collection
		// If they are, authenticate them against the default collection regardless of the requested collection
		if ($collection !== $defaultCollection) {
			$superAdminUser = $this->tryAuthenticateSuperAdmin($email, $password);
			if ($superAdminUser !== null) {
				return $superAdminUser;
			}
		}

		if ($collection === '') {
			$collection = $defaultCollection;
		}

		// Normal authentication flow for the requested collection
		$user   = $this->validator->validateUserByEmail($email, $collection);
		$userId = $user['id'];

		$this->account = "$collection/$userId";

		$this->testUserActive($user);
		$this->testUserExpiration($user);
		$this->testUserMaxLoginCount($user);

		if (!password_verify($password, (string)$user['password'])) {
			$error = "{$this->account}: Invalid password";
			$this->logger->error($error);
			throw new \Exception($error);
		}

		// Update the last login date of the user
		$this->updateService->updateLoginDate($collection, $user['id']);

		$this->logger->info("User {$this->account} logged in");

		$this->eventDispatcher->dispatch('user.login', new UserEventPayload($user['id'] ?? $this->account));

		return $user;
	}

	/**
	 * Try to authenticate a user as SuperAdmin against the default collection.
	 *
	 * @return array<string,mixed>|null User data if SuperAdmin authentication succeeds, null otherwise
	 */
	private function tryAuthenticateSuperAdmin(string $email, string $password): ?array
	{
		try {
			$defaultCollection = $this->config->auth['collection'];

			// Try to find and validate the user in the default collection
			$user   = $this->validator->validateUserByEmail($email, $defaultCollection);
			$userId = $user['id'];

			// Check if this user is a SuperAdmin
			if (!$this->validator->isSuperAdmin($userId)) {
				return null; // Not a SuperAdmin
			}

			// Set account for logging
			$this->account = "$defaultCollection/$userId";

			// Run all the standard validation tests
			$this->testUserActive($user);
			$this->testUserExpiration($user);
			$this->testUserMaxLoginCount($user);

			// Verify password
			if (!password_verify($password, (string)$user['password'])) {
				return null; // Invalid password - fall back to normal auth
			}

			// Update the last login date
			$this->updateService->updateLoginDate($defaultCollection, $user['id']);

			$this->logger->info("SuperAdmin {$this->account} logged in via cross-collection authentication");

			// Mark this user data to indicate it was authenticated as SuperAdmin from default collection
			$user['_authenticated_collection'] = $defaultCollection;

			return $user;
		} catch (\Throwable) {
			// If any step fails, return null to fall back to normal authentication
			return null;
		}
	}

	/** @param array<string,mixed> $user */
	private function testUserActive(array $user): void
	{
		if (!isset($user['active']) || !$user['active']) {
			$error = "User account {$this->account} is not active";
			$this->logger->error($error);
			throw new \Exception($error);
		}
	}

	/** @param array<string,mixed> $user */
	private function testUserExpiration(array $user): void
	{
		if (
			isset($user['expiration'])
			&& !empty($user['expiration'])
			&& strtotime((string)$user['expiration']) < time()
		) {
			$error = "User account {$this->account} has expired";
			$this->logger->error($error);
			throw new \Exception($error);
		}
	}

	/** @param array<string,mixed> $user */
	private function testUserMaxLoginCount(array $user): void
	{
		if (
			isset($user['maxLoginCount'], $user['loginCount'])
			&& $user['maxLoginCount'] > 0
			&& $user['loginCount'] >= $user['maxLoginCount']
		) {
			$error = "User account {$this->account} has reached the maximum login count";
			$this->logger->error($error);
			throw new \Exception($error);
		}
	}
}
