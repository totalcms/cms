<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ApiKey\Service;

use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;

/**
 * Service for deleting API keys.
 */
readonly class ApiKeyDeleter
{
	public function __construct(
		private ApiKeyRepository $repository,
	) {
	}

	/**
	 * Delete an API key by ID.
	 */
	public function deleteKey(string $id): bool
	{
		return $this->repository->delete($id);
	}
}
