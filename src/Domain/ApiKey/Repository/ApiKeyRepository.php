<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ApiKey\Repository;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageRepository;

/**
 * Repository for managing API keys stored in .system/apikeys.json.
 */
class ApiKeyRepository extends StorageRepository
{
	private const FILE_PATH = '.system/apikeys.json';

	public function __construct(
		StorageAdapterInterface $filesystem,
	) {
		parent::__construct($filesystem);
	}

	/**
	 * Get all API keys.
	 *
	 * @return array<ApiKeyData>
	 */
	public function getAll(): array
	{
		$data = $this->readFile();

		return array_map(
			fn (array $keyData): ApiKeyData => new ApiKeyData($keyData),
			$data['apikeys'] ?? []
		);
	}

	/**
	 * Find an API key by its ID.
	 */
	public function findById(string $id): ?ApiKeyData
	{
		$keys = $this->getAll();

		foreach ($keys as $key) {
			if ($key->id === $id) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Find an API key by its key string.
	 */
	public function findByKey(string $key): ?ApiKeyData
	{
		$keys = $this->getAll();

		foreach ($keys as $apiKey) {
			if ($apiKey->key === $key) {
				return $apiKey;
			}
		}

		return null;
	}

	/**
	 * Save a new API key.
	 */
	public function save(ApiKeyData $apiKey): void
	{
		$data           = $this->readFile();
		$data['apikeys'] ??= [];

		// Add the new key
		$data['apikeys'][] = $apiKey->toArray();

		$this->writeFile($data);
	}

	/**
	 * Update an existing API key (typically for lastUsed).
	 */
	public function update(ApiKeyData $apiKey): void
	{
		$data = $this->readFile();
		$keys = $data['apikeys'] ?? [];

		// Find and update the key
		foreach ($keys as $index => $keyData) {
			if ($keyData['id'] === $apiKey->id) {
				$keys[$index] = $apiKey->toArray();
				break;
			}
		}

		$data['apikeys'] = $keys;
		$this->writeFile($data);
	}

	/**
	 * Delete an API key by ID.
	 */
	public function delete(string $id): bool
	{
		$data = $this->readFile();
		$keys = $data['apikeys'] ?? [];

		$originalCount = count($keys);
		$keys          = array_filter($keys, fn (array $keyData): bool => $keyData['id'] !== $id);

		if (count($keys) === $originalCount) {
			return false; // Key not found
		}

		$data['apikeys'] = array_values($keys); // Re-index array
		$this->writeFile($data);

		return true;
	}

	/**
	 * Update the lastUsed timestamp for a key.
	 */
	public function updateLastUsed(string $keyString): void
	{
		$apiKey = $this->findByKey($keyString);

		if (!$apiKey instanceof ApiKeyData) {
			return;
		}

		// Create updated version with new lastUsed
		$updatedKey = new ApiKeyData([
			'id'       => $apiKey->id,
			'name'     => $apiKey->name,
			'key'      => $apiKey->key,
			'created'  => $apiKey->created,
			'lastUsed' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'   => $apiKey->scopes,
		]);

		$this->update($updatedKey);
	}

	/**
	 * Read the JSON file.
	 *
	 * @return array<string,mixed>
	 */
	private function readFile(): array
	{
		if (!$this->filesystem->fileExists(self::FILE_PATH)) {
			return ['apikeys' => []];
		}

		$content = $this->filesystem->read(self::FILE_PATH);

		if ($content === '') {
			return ['apikeys' => []];
		}

		$data = json_decode($content, true);

		return is_array($data) ? $data : ['apikeys' => []];
	}

	/**
	 * Write to the JSON file.
	 *
	 * @param array<string,mixed> $data
	 */
	private function writeFile(array $data): void
	{
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if ($json === false) {
			throw new \RuntimeException('Failed to encode API keys to JSON: ' . json_last_error_msg());
		}

		$this->filesystem->write(self::FILE_PATH, $json);
	}
}
