<?php

declare(strict_types=1);

namespace TotalCMS\Domain\AccessGroup\Service;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Repository\AccessGroupRepository;

/**
 * Service for managing access groups (save, delete).
 */
readonly class AccessGroupManager
{
	public function __construct(
		private AccessGroupRepository $repository,
	) {
	}

	/**
	 * Save (create or update) an access group.
	 *
	 * @throws \RuntimeException
	 */
	public function save(AccessGroupData $group): void
	{
		// Prevent creating/editing 'admin' group
		if ($group->id === 'admin') {
			throw new \RuntimeException('Cannot modify the admin group');
		}

		$this->repository->save($group);
	}

	/**
	 * Delete an access group.
	 *
	 * @throws \RuntimeException
	 */
	public function delete(string $id): bool
	{
		// Protection against deleting admin group is in the repository
		return $this->repository->delete($id);
	}

	/**
	 * Create default access groups if they don't exist.
	 */
	public function createDefaultGroups(): void
	{
		$this->repository->createDefaultGroups();
	}
}
