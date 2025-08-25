<?php

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

readonly class UserValidationService
{
	public const ADMINGROUP = 'admin';

	public function __construct(
		private IndexSearcher $searcher,
		private ObjectFetcher $objectFetcher,
		private Config $config,
	) {
	}

	/** @return array<string,mixed> */
	public function validateUserByEmail(string $email, string $collection = ''): array
	{
		if ($collection === '') {
			$collection = $this->config->auth['collection'];
		}

		$users = $this->searcher->searchByProperty($collection, 'email', $email);
		$first = $users->first();

		if ($users->isEmpty() || is_null($first)) {
			throw new \Exception('User not found');
		}

		return $this->validateUserById($first['id'], $collection);
	}

	/** @return array<string,mixed> */
	public function validateUserById(string $userId, string $collection = ''): array
	{
		if ($collection === '') {
			$collection = $this->config->auth['collection'];
		}

		if ($userId === '') {
			throw new \InvalidArgumentException('No User ID provided');
		}

		if (!$this->objectFetcher->existsObject($collection, $userId)) {
			// Admin users from default auth collection are always allowed
			if ($collection !== $this->config->auth['collection'] && $this->isSuperAdmin($userId)) {
				return $this->validateUserById($userId);
			}
			throw new \Exception("User $userId does not exist");
		}

		return $this->objectFetcher->fetchObject($collection, $userId)->toArray();
	}

	/** @param string|array<string> $groups */
	public function validateUserInGroups(string $userId, string|array $groups, string $collection = ''): bool
	{
		if (is_string($groups)) {
			$groups = [$groups];
		}

		try {
			$user = $this->validateUserById($userId, $collection);
		} catch (\Exception) {
			return false;
		}
		// Admin users are always allowed
		$groups[] = self::ADMINGROUP;
		$found    = array_intersect($groups, $user['groups']);

		return $found !== [];
	}

	public function isSuperAdmin(string $userId): bool
	{
		$collection = $this->config->auth['collection'];

		if ($this->objectFetcher->existsObject($collection, $userId)) {
			$user = $this->objectFetcher->fetchObject($collection, $userId)->toArray();
			if (in_array(self::ADMINGROUP, $user['groups'])) {
				return true;
			}
		}

		return false;
	}
}
