<?php

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

final class UserValidationService
{
	public const ADMINGROUP = 'admin';

	public function __construct(
		private IndexSearcher $searcher,
		private ObjectFetcher $objectFetcher,
		private Config $config,
	) {}

	/** @return array<string,mixed> */
	public function validateUserByEmail(string $email, string $collection = ''): array
	{
		if (empty($collection)) {
			$collection = $this->config->auth['collection'];
		}

		$users = $this->searcher->searchByProperty($collection, 'email', $email);

		if (empty($users)) {
			throw new \Exception('User not found');
		}

		$userId = array_shift($users)['id'];

		return $this->validateUserById($userId, $collection);
	}

	/** @return array<string,mixed> */
	public function validateUserById(string $userId, string $collection = ''): array
	{
		if (empty($collection)) {
			$collection = $this->config->auth['collection'];
		}

		if (!$this->objectFetcher->existsObject($collection, $userId)) {
			throw new \Exception("User $userId does not exist");
		}

		$user = $this->objectFetcher->fetchObject($collection, $userId)->toArray();

		return $user;
	}

	/** @param array<string> $groups */
	public function validateUserInGroups(string $userId, array $groups, string $collection = ''): bool
	{
		try {
			$user = $this->validateUserById($userId, $collection);
		} catch (\Exception $e) {
			return false;
		}
		// Admin users are always allowed
		$groups[] = self::ADMINGROUP;
		$found = array_intersect($groups, $user['groups']);

		return !empty($found);
	}
}
