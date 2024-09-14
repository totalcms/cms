<?php

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

final class LoginService
{
	public function __construct(
		private IndexSearcher $searcher,
		private ObjectFetcher $objectFetcher,
		private LoginUpdateService $updateService,
		private FirstLoginChecker $firstLoginChecker,
		private Config $config,
	) {}

	/**
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @return array<string,mixed>
	 */
	public function authenticate(string $email, string $password, string $collection = ''): array
	{
		if (empty($collection)) {
			if ($this->firstLoginChecker->isNewInstallation()) {
				$this->firstLoginChecker->createFirstUser($email, $password);
			}
			$collection = $this->config->auth['collection'];
		}

		$users = $this->searcher->searchByProperty($collection, 'email', $email);

		if (empty($users)) {
			throw new \Exception('User not found');
		}

		$userId = $users[0]['id'];

		if (!$this->objectFetcher->existsObject($collection, $userId)) {
			throw new \Exception("User $userId does not exist");
		}

		$user = $this->objectFetcher->fetchObject($collection, $userId)->toArray();

		if (!isset($user['active']) || !$user['active']) {
			throw new \Exception('User account is not active');
		}

		if (isset($user['expiration']) && strtotime($user['expiration']) > time()) {
			throw new \Exception('User account has expired');
		}

		if (!password_verify($password, $user['password'])) {
			throw new \Exception('Invalid password');
		}

		// Update the last login date of the user
		$this->updateService->updateLoginDate($collection, $user['id']);

		return $user;
	}
}
