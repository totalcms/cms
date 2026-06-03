<?php

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

readonly class UserValidationService
{
	public const ADMINGROUP = 'admin';

	/**
	 * The reserved fallback group. In the file-access context (see
	 * {@see validateFileAccess()}) its presence in a collection's File Access
	 * Groups means "any authenticated user", not just groupless users.
	 */
	public const DEFAULTGROUP = 'default';

	public function __construct(
		private IndexSearcher $searcher,
		private ObjectFetcher $objectFetcher,
		private Config $config,
	) {
	}

	/**
	 * Find a user by email address or user ID, based on the loginWith setting.
	 *
	 * @return array<string,mixed>
	 */
	public function validateUser(string $idOrEmail, string $collection = ''): array
	{
		$loginWith = $this->config->auth['loginWith'] ?? 'both';

		if ($loginWith === 'email') {
			return $this->validateUserByEmail($idOrEmail, $collection);
		}

		if ($loginWith === 'id') {
			return $this->validateUserById($idOrEmail, $collection);
		}

		// 'both' — use @ to determine which lookup to use
		if (str_contains($idOrEmail, '@')) {
			return $this->validateUserByEmail($idOrEmail, $collection);
		}

		return $this->validateUserById($idOrEmail, $collection);
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
			throw new \Exception('User not found with email: ' . $email);
		}

		return $this->validateUserById($first['id'], $collection);
	}

	/**
	 * Find a user by email — returns the ObjectData or null if not found.
	 * Use this when callers must NOT reveal whether a user exists
	 * (password reset, email verification, resend verification — anti-enumeration).
	 * Contrast with {@see validateUserByEmail()} which throws on miss for the
	 * login path where presence is a precondition.
	 */
	public function findUserByEmail(string $email, string $collection = ''): ?ObjectData
	{
		if ($collection === '') {
			$collection = (string)($this->config->auth['collection'] ?? '');
		}

		try {
			$users = $this->searcher->searchByProperty($collection, 'email', $email);
			$first = $users->first();

			if ($users->isEmpty() || is_null($first)) {
				return null;
			}

			return $this->objectFetcher->fetchObject($collection, $first['id']);
		} catch (\Exception) {
			return null;
		}
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

	/**
	 * Authorize access to a collection's protected files.
	 *
	 * Identical to {@see validateUserInGroups()} except the reserved
	 * {@see DEFAULTGROUP} group grants access to ANY authenticated user
	 * (regardless of their own group membership), not just groupless users.
	 * Used only by the file download/stream consumers — group gating elsewhere
	 * (builder pages, registration) continues to use validateUserInGroups().
	 *
	 * @param string|array<string> $groups
	 */
	public function validateFileAccess(string $userId, string|array $groups, string $collection = ''): bool
	{
		if (is_string($groups)) {
			$groups = [$groups];
		}

		if (in_array(self::DEFAULTGROUP, $groups, true)) {
			// "default" in File Access Groups means any logged-in user — the
			// only requirement is that the user actually exists.
			try {
				$this->validateUserById($userId, $collection);

				return true;
			} catch (\Exception) {
				return false;
			}
		}

		return $this->validateUserInGroups($userId, $groups, $collection);
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
