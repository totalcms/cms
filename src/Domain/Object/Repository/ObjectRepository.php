<?php

namespace TotalCMS\Domain\Object\Repository;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Schema\Service\SchemaValidator;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

final class ObjectRepository extends StorageRepository
{
	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly ObjectFactory $factory,
		private readonly SchemaValidator $validator,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CacheManager $cacheManager,
	) {
		parent::__construct($filesystem);
	}

	/**
     * Save an object.
     *
     *
     */
    public function saveObject(string $collection, ObjectData $object): void
	{
		if (in_array($object->id, ObjectData::RESERVED_NAMES)) {
			throw new \UnexpectedValueException('Cannot save object with a reserved name:' . $object->id);
		}

		$collectionInfo = $this->collectionFetcher->fetchCollection($collection);

		if (!$collectionInfo instanceof CollectionData) {
			throw new \UnexpectedValueException('Collection not found: ' . $collection);
		}

		if ($this->validator->validateSchema($object->toArray(), $collectionInfo->schema) === false) {
			throw new \UnexpectedValueException('Invalid object data provided. Failed schema validation.', 1);
		}

		$objectFile = $this->buildObjectPath($collection, $object->id);

		$this->filesystem->write($objectFile, $object->toJson());

		// Invalidate object cache when saved (data has changed)
		$cacheKey = "object:{$collection}:{$object->id}";
		$this->invalidateObjectCache($cacheKey, $collection);
	}

	public function existsObject(string $collection, string $id): bool
	{
		$objectFile = $this->buildObjectPath($collection, $id);

		return $this->filesystem->fileExists($objectFile);
	}

	public function fetchObject(string $collection, string $id): ?ObjectData
	{
		// Try cache first (Redis preferred for fast object access)
		$cacheKey = "object:{$collection}:{$id}";
		$cached   = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			// Return cached object (data is stored as array for serialization)
			return $this->factory->generateObject($collection, $cached);
		}

		// Cache miss - fetch from filesystem
		$objectFile = $this->buildObjectPath($collection, $id);

		if ($this->filesystem->fileExists($objectFile)) {
			$contents = json_decode($this->filesystem->read($objectFile), true);
			if (is_array($contents)) {
				// Cache the raw data (not the ObjectData instance) for 1 hour
				$this->cacheManager->storeComputedData($cacheKey, $contents, CacheManager::TTL_OBJECT_DATA);

				return $this->factory->generateObject($collection, $contents);
			}
		}

		// If object file doesn't exist, invalidate cache to prevent stale data
		$this->cacheManager->clearComputedData($cacheKey);

		return null;
	}

	public function deleteObject(string $collection, string $id): bool
	{
		$filesPath  = $this->buildObjectFilesPath($collection, $id);
		$objectFile = $this->buildObjectPath($collection, $id);

		$this->filesystem->deleteDirectory($filesPath);
		$deleted = $this->filesystem->delete($objectFile);

		// Invalidate object cache when deleted
		if ($deleted) {
			$cacheKey = "object:{$collection}:{$id}";
			$this->invalidateObjectCache($cacheKey, $collection);
		}

		return $deleted;
	}

	/**
	 * Invalidate object cache and related caches.
	 */
	private function invalidateObjectCache(string $objectCacheKey, string $collection): void
	{
		// Remove the specific object from cache
		$this->cacheManager->clearComputedData($objectCacheKey);

		// Also invalidate collection index cache (objects list has changed)
		$this->cacheManager->clearCollectionIndex($collection);
	}

	public function copyObjectFiles(string $fromCollection, string $fromId, string $toCollection, string $toId): void
	{
		$fromPath = $this->buildObjectFilesPath($fromCollection, $fromId);
		$toPath   = $this->buildObjectFilesPath($toCollection, $toId);

		if ($this->filesystem->directoryExists($fromPath)) {
			$this->filesystem->copyDirectory($fromPath, $toPath);
		}
	}

	private function buildObjectFilesPath(string $collection, string $id): string
	{
		return PathUtils::buildPath(collection: $collection, filename: $id);
	}

	private function buildObjectPath(string $collection, string $id): string
	{
		return PathUtils::buildPath(collection: $collection, filename: $id . self::FILE_EXT);
	}
}
