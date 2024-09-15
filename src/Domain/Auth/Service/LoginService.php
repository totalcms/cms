<?php

namespace TotalCMS\Domain\Auth\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Support\Config;
use TotalCMS\Factory\LoggerFactory;

final class LoginService
{
	const ACCESS_LOG = 'access.log';

	private LoggerInterface $logger;

	public function __construct(
		private UserValidationService $validator,
		private LastLoginUpdateService $updateService,
		private FirstLoginChecker $firstLoginChecker,
		private LoggerFactory $loggerFactory,
		private Config $config,
	) {
		$this->logger = $this->loggerFactory->addFileHandler(self::ACCESS_LOG)->createLogger();
	}

	/** @return array<string,mixed> */
	public function authenticate(string $email, string $password, string $collection = ''): array
	{
		if (empty($collection)) {
			$collection = $this->config->auth['collection'];

			if ($this->firstLoginChecker->isNewInstallation()) {
				$this->logger->info('First login detected, creating first user');
				$this->firstLoginChecker->createFirstUser($email, $password);
			}
		}

		$user = $this->validator->validateUserByEmail($email, $collection);
		$userId = $user['id'];

		if (!isset($user['active']) || !$user['active']) {
			$error = "User account $collection/$userId is not active";
			$this->logger->error($error);
			throw new \Exception($error);
		}

		if (isset($user['expiration']) && strtotime($user['expiration']) > time()) {
			$error = "User account $collection/$userId has expired";
			$this->logger->error($error);
			throw new \Exception($error);
		}

		if (!password_verify($password, $user['password'])) {
			$error = "$collection/$userId: Invalid password";
			$this->logger->error($error);
			throw new \Exception($error);
		}

		// Update the last login date of the user
		$this->updateService->updateLoginDate($collection, $user['id']);

		$this->logger->info("User $collection/$userId logged in");

		return $user;
	}
}
