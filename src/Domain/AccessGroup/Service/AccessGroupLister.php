<?php

declare(strict_types=1);

namespace TotalCMS\Domain\AccessGroup\Service;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Repository\AccessGroupRepository;

/**
 * Service for listing access groups.
 */
readonly class AccessGroupLister
{
	public function __construct(
		private AccessGroupRepository $repository,
	) {
	}

	/**
	 * List all access groups.
	 *
	 * @return array<AccessGroupData>
	 */
	public function listAll(): array
	{
		return $this->repository->getAll();
	}

	/**
	 * Get all access group IDs.
	 *
	 * @return array<string>
	 */
	public function listAllIds(): array
	{
		return $this->repository->getAllIds();
	}

	/**
	 * Find an access group by ID.
	 */
	public function findById(string $id): ?AccessGroupData
	{
		return $this->repository->findById($id);
	}

	/**
	 * Check if an access group exists.
	 */
	public function exists(string $id): bool
	{
		return $this->repository->exists($id);
	}

	/**
	 * Ensure the 'default' group exists, creating it if necessary.
	 * Used for backwards compatibility with existing installations.
	 */
	public function ensureDefaultGroupExists(): ?AccessGroupData
	{
		return $this->repository->ensureDefaultGroupExists();
	}
}
