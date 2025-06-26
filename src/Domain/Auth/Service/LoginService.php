<?php

namespace TotalCMS\Domain\Auth\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class LoginService
{
	public const ACCESS_LOG = 'totalcms-access.log';

	private LoggerInterface $logger;
	private string $account = '';

	public function __construct(
		private UserValidationService $validator,
		private LastLoginUpdateService $updateService,
		private FirstLoginChecker $firstLoginChecker,
		private LoggerFactory $loggerFactory,
		private Config $config,
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

		if (empty($collection)) {
			$collection = $this->config->auth['collection'];
		}

		$user   = $this->validator->validateUserByEmail($email, $collection);
		$userId = $user['id'];

		$this->account = "$collection/$userId";

		$this->testUserActive($user);
		$this->testUserExpiration($user);
		$this->testUserMaxLoginCount($user);

		if (!password_verify($password, $user['password'])) {
			$error = "{$this->account}: Invalid password";
			$this->logger->error($error);
			throw new \Exception($error);
		}

		// Update the last login date of the user
		$this->updateService->updateLoginDate($collection, $user['id']);

		$this->logger->info("User {$this->account} logged in");

		return $user;
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
			&& strtotime($user['expiration']) < time()
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
