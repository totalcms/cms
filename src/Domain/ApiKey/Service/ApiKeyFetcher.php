<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ApiKey\Service;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;

/**
 * Service for fetching and validating API keys.
 */
readonly class ApiKeyFetcher
{
	public function __construct(
		private ApiKeyRepository $repository,
		private ApiKeyPermissionChecker $permissionChecker,
	) {
	}

	/**
	 * Validate an API key and check if it has permission for the request.
	 *
	 * @param string $keyString The API key to validate
	 * @param string $method HTTP method (GET, POST, etc.)
	 * @param string $path Request path
	 *
	 * @return ApiKeyData|null Returns the ApiKeyData if valid, null if invalid
	 */
	public function validateKey(string $keyString, string $method, string $path): ?ApiKeyData
	{
		$apiKey = $this->repository->findByKey($keyString);

		if (!$apiKey instanceof ApiKeyData) {
			return null;
		}

		// Check permissions using the permission checker service
		if (!$this->permissionChecker->allows($apiKey, $method, $path)) {
			return null;
		}

		// Update last used timestamp
		$this->repository->updateLastUsed($keyString);

		return $apiKey;
	}

	/**
	 * Get all API keys.
	 *
	 * @return array<ApiKeyData>
	 */
	public function getAllKeys(): array
	{
		return $this->repository->getAll();
	}
}
