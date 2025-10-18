<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ApiKey\Service;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;

/**
 * Service for creating API keys.
 */
readonly class ApiKeyCreator
{
	public function __construct(
		private ApiKeyRepository $repository,
	) {
	}

	/**
	 * Generate a new API key.
	 *
	 * @param string $name Human-readable name for the key
	 * @param array<string,mixed> $scopes Permissions (methods, paths)
	 * @return ApiKeyData
	 * @throws \InvalidArgumentException If validation fails
	 */
	public function createApiKey(string $name, array $scopes): ApiKeyData
	{
		$methods = $scopes['methods'] ?? [];
		$paths   = $scopes['paths'] ?? [];

		// Validate name
		if ($name === '') {
			throw new \InvalidArgumentException('API key name is required');
		}

		// Validate methods
		if ($methods === []) {
			throw new \InvalidArgumentException('At least one HTTP method must be selected');
		}

		// Validate paths
		if ($paths === []) {
			throw new \InvalidArgumentException('At least one endpoint must be selected');
		}

		$apiKey = new ApiKeyData([
			'id'       => $this->generateUuid(),
			'name'     => $name,
			'key'      => $this->generateKey(),
			'created'  => gmdate('Y-m-d\TH:i:s\Z'),
			'lastUsed' => null,
			'scopes'   => $scopes,
		]);

		$this->repository->save($apiKey);

		return $apiKey;
	}

	/**
	 * Generate a secure API key with tcms_ prefix.
	 */
	private function generateKey(): string
	{
		// Generate 32 random bytes = 64 hex characters
		$randomBytes = random_bytes(32);
		$hexString   = bin2hex($randomBytes);

		return 'tcms_' . $hexString;
	}

	/**
	 * Generate a UUID v4.
	 */
	private function generateUuid(): string
	{
		$data = random_bytes(16);

		// Set version (4) and variant bits
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
