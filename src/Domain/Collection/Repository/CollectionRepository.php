<?php

namespace TotalCMS\Domain\Collection\Repository;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaValidator;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Repository.
 */
class CollectionRepository extends StorageRepository
{
	private const META_FILE = '.meta.json';

	/**
	 * The constructor.
	 *
	 * @param StorageFilesystemAdapter $filesystem The filesystem factory
	 */
	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly CollectionFactory $factory,
		private readonly SchemaValidator $validator,
		private readonly CacheManager $cacheManager,
		private readonly IndexRepository $indexRepository,
	) {
		parent::__construct($filesystem);
	}

	/**
	 * List all Collections.
	 *
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @return array<CollectionData>
	 */
	public function listAllCollections(): array
	{
		// Try cache first (Redis preferred for fast access)
		$cached = $this->cacheManager->getComputedData('collections_list');

		if ($cached !== null && is_array($cached)) {
			// Convert cached data back to CollectionData objects
			$collections = [];
			foreach ($cached as $collectionArray) {
				if (is_array($collectionArray)) {
					$collections[] = $this->factory->generateCollection($collectionArray);
				}
			}

			return $collections;
		}

		// Cache miss - fetch from filesystem
		// Check if directory exists before trying to list it (prevents auto-creation during setup)
		if (!$this->filesystem->directoryExists('')) {
			// Directory doesn't exist - return empty array and cache it
			$this->cacheManager->storeComputedData('collections_list', [], CacheManager::TTL_COLLECTIONS_LIST);

			return [];
		}

		$collections = [];
		foreach ($this->filesystem->listDirectories('') as $id) {
			$collection = $this->fetchCollection($id);
			if ($collection == null) {
				continue;
			}
			$collections[] = $collection;
		}

		// Always cache the result, even if empty
		// This ensures consistent behavior and prevents null cache values
		$collectionsArray = array_map(fn (CollectionData $collection): array => $collection->toArray(), $collections);
		$this->cacheManager->storeComputedData('collections_list', $collectionsArray, CacheManager::TTL_COLLECTIONS_LIST);

		return $collections;
	}

	public function fetchCollection(string $collection): ?CollectionData
	{
		$metaFile       = $this->buildMetaPath($collection);
		$collectionData = $this->fetchAndDeserialize($metaFile, CollectionData::class);

		if ($collectionData === null) {
			return null;
		}

		// Auto-calculate totalObjects if missing (backward compatibility)
		// Calculate in-memory only - values persist on next normal save operation
		if ($collectionData->totalObjects === 0) {
			$collectionData->totalObjects = $this->calculateObjectCount($collection);
		}

		return $collectionData;
	}

	/**
	 * Calculate the number of objects in a collection from the index.
	 */
	private function calculateObjectCount(string $collection): int
	{
		try {
			$index = $this->indexRepository->fetchIndex($collection);

			if (!$index instanceof IndexData) {
				return 0;
			}

			return $index->objects->count();
		} catch (\Exception) {
			// No index or error reading - return 0
			return 0;
		}
	}

	public function deleteCollection(string $collection): bool
	{
		$metaFile = $this->buildMetaPath($collection);

		$result = $this->filesystem->delete($metaFile);

		// Clear caches after deleting collection
		if ($result) {
			$this->cacheManager->clearComputedData('collections_list');
			$this->cacheManager->clearCollectionIndex($collection);
		}

		return $result;
	}

	/**
	 * Verify that a collection exists.
	 */
	public function collectionExists(string $collection): bool
	{
		$metaFile = $this->buildMetaPath($collection);

		return $this->filesystem->fileExists($metaFile);
	}

	/**
	 * Fetch a collection.
	 *
	 * @throws \DomainException
	 */
	public function getCollection(string $collectionName): CollectionData
	{
		$collection = $this->fetchCollection($collectionName);

		if (!$collection instanceof CollectionData) {
			throw new \DomainException(sprintf('Collection does not exist: %s', $collectionName));
		}
		if ($collection->isValid() === false) {
			throw new \DomainException(sprintf('Collection is invalid: %s', $collectionName));
		}

		return $collection;
	}

	/**
	 * Save a Collection.
	 *
	 * @param CollectionData $collection The collection to save
	 */
	public function saveCollection(CollectionData $collection): void
	{
		if (in_array($collection->id, CollectionData::RESERVED_NAMES)) {
			throw new \UnexpectedValueException('Cannot save collection with a reserved name');
		}

		if ($this->validator->validateSchema($collection->toArray(), 'collection') === false) {
			throw new \UnexpectedValueException('Invalid Collection data provided. Failed schema validation.', 1);
		}

		$jsonContent = $collection->toJson();
		$metaFile    = $this->buildMetaPath($collection->id);

		$this->filesystem->write($metaFile, $jsonContent);

		// Clear caches after saving collection
		$this->cacheManager->clearComputedData('collections_list');
		$this->cacheManager->clearCollectionIndex($collection->id);
	}

	public function isReservedCollection(string $collectionId): bool
	{
		return in_array($collectionId, SchemaData::RESERVED_SCHEMAS);
	}

	/**
	 * Create a collection for a reserved collection.
	 *
	 * @param string $collectionId The collection id
	 */
	public function saveReservedCollection(string $collectionId): void
	{
		if ($this->isReservedCollection($collectionId)) {
			$collection = $this->factory->generateReservedCollection($collectionId);
			$this->saveCollection($collection);
		}
	}

	private function buildMetaPath(string $collection): string
	{
		return PathUtils::buildPath(collection: $collection, filename: self::META_FILE);
	}
}
