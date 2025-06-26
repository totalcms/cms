<?php

namespace TotalCMS\Domain\Collection\Repository;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaValidator;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Utils\PathUtils;

/**
 * Repository.
 */
final class CollectionRepository extends StorageRepository
{
	private const META_FILE = '.meta.json';
	private CollectionFactory $factory;
	private SchemaValidator $validator;
	private CacheManager $cacheManager;

	/**
	 * The constructor.
	 *
	 * @param StorageFilesystemAdapter $filesystem The filesystem factory
	 * @param CollectionFactory $factory
	 * @param SchemaValidator $validator
	 * @param CacheManager $cacheManager
	 */
	public function __construct(
		StorageAdapterInterface $filesystem,
		CollectionFactory $factory,
		SchemaValidator $validator,
		CacheManager $cacheManager,
	) {
		parent::__construct($filesystem);

		$this->factory      = $factory;
		$this->validator    = $validator;
		$this->cacheManager = $cacheManager;
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
		$collections = [];
		foreach ($this->filesystem->listDirectories('') as $id) {
			$collection = $this->fetchCollection($id);
			if ($collection == null) {
				continue;
			}
			$collections[] = $collection;
		}

		if (empty($collections)) {
			// Clear cache if no collections to prevent serving stale data
			$this->cacheManager->clearComputedData('collections_list');
		} else {
			// Cache the collections as arrays for 15 minutes (collections don't change often)
			$collectionsArray = array_map(fn ($collection) => $collection->toArray(), $collections);
			$this->cacheManager->storeComputedData('collections_list', $collectionsArray, CacheManager::TTL_COLLECTIONS_LIST);
		}

		return $collections;
	}

	public function fetchCollection(string $collection): ?CollectionData
	{
		$metaFile = $this->buildMetaPath($collection);

		return $this->fetchAndDeserialize($metaFile, CollectionData::class);
	}

	public function deleteCollection(string $collection): bool
	{
		$metaFile = $this->buildMetaPath($collection);

		return $this->filesystem->delete($metaFile);
	}

	/**
	 * Verify that a collection exists.
	 *
	 * @param string $collection
	 */
	public function collectionExists(string $collection): bool
	{
		$metaFile = $this->buildMetaPath($collection);

		return $this->filesystem->fileExists($metaFile);
	}

	/**
	 * Fetch a collection.
	 *
	 * @param string $collectionName
	 *
	 * @throws \DomainException
	 *
	 * @return CollectionData
	 */
	public function getCollection(string $collectionName): CollectionData
	{
		$collection = $this->fetchCollection($collectionName);

		if ($collection === null) {
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
	 *
	 * @return void
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
