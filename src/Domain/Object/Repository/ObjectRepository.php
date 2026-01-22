<?php

namespace TotalCMS\Domain\Object\Repository;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Service\SchemaValidator;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

class ObjectRepository extends StorageRepository
{
	/**
	 * Request-level memoization cache for objects.
	 * Stores raw object data to avoid multiple cache/filesystem reads within a single request.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $requestCache = [];

	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly ObjectFactory $factory,
		private readonly SchemaValidator $validator,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CacheManager $cacheManager,
		private readonly SchemaRepository $schemaRepository,
		private readonly IndexRepository $indexRepository,
	) {
		parent::__construct($filesystem);
	}

	/**
	 * Save an object.
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

		// Validate unique property constraints
		$this->validateUniqueProperties($object, $collectionInfo, $collection);

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
		$cacheKey = "object:{$collection}:{$id}";

		// Try request-level cache first (fastest - in-memory)
		if (isset($this->requestCache[$cacheKey])) {
			return $this->factory->generateObject($collection, $this->requestCache[$cacheKey]);
		}

		// Try persistent cache second (Redis/APCu/etc - fast)
		// Note: CacheManager handles disableCache() check internally
		$cached = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			// Store in request cache for subsequent access in this request
			$this->requestCache[$cacheKey] = $cached;

			return $this->factory->generateObject($collection, $cached);
		}

		// Cache miss - fetch from filesystem (slowest)
		$objectFile = $this->buildObjectPath($collection, $id);

		if ($this->filesystem->fileExists($objectFile)) {
			$contents = json_decode($this->filesystem->read($objectFile), true);
			if (is_array($contents)) {
				// Cache the raw data in both caches
				$this->cacheManager->storeComputedData($cacheKey, $contents, CacheManager::TTL_OBJECT_DATA);
				$this->requestCache[$cacheKey] = $contents;

				return $this->factory->generateObject($collection, $contents);
			}
		}

		// If object file doesn't exist, invalidate cache to prevent stale data
		$this->cacheManager->clearComputedData($cacheKey);

		return null;
	}

	/**
	 * Fetch object directly from disk, bypassing all caches.
	 * Use for bulk operations like index building where fresh data is required.
	 */
	public function fetchObjectFromDisk(string $collection, string $id): ?ObjectData
	{
		$objectFile = $this->buildObjectPath($collection, $id);

		if (!$this->filesystem->fileExists($objectFile)) {
			return null;
		}

		$contents = json_decode($this->filesystem->read($objectFile), true);
		if (!is_array($contents)) {
			return null;
		}

		return $this->factory->generateObject($collection, $contents);
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
		// Remove from request cache (in-memory)
		unset($this->requestCache[$objectCacheKey]);

		// Remove the specific object from persistent cache
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

	/**
	 * Validate unique property constraints using cached index data.
	 *
	 * NOTE: This validation is placed in the Repository (not in a separate validator service)
	 * to avoid circular dependency issues. ObjectRepository is part of IndexSearcher's
	 * dependency chain, so we cannot use IndexSearcher or any service that depends on it.
	 * Instead, we use IndexRepository and SchemaRepository directly, which leverage caching
	 * for better performance and don't create any circular dependencies.
	 *
	 * @throws \DomainException if duplicate value found
	 */
	private function validateUniqueProperties(ObjectData $object, CollectionData $collectionInfo, string $collection): void
	{
		// Use SchemaRepository to leverage caching
		$schema     = $this->schemaRepository->getSchema($collectionInfo->schema);
		$objectData = $object->toArray();

		// Check each property for unique constraint
		foreach ($schema->properties as $property => $propertyConfig) {
			// Skip if not marked as unique
			if (!isset($propertyConfig['unique']) || $propertyConfig['unique'] !== true) {
				continue;
			}

			// Verify property is in the index (required for uniqueness checking)
			if (!in_array($property, $schema->index, true)) {
				$label = $propertyConfig['label'] ?? $property;
				throw new \DomainException("Property '{$label}' is marked as unique but is not included in the schema index. Add '{$property}' to the index array in the schema.");
			}

			// Skip if property not set (isset returns false for null too)
			if (!isset($objectData[$property])) {
				continue;
			}

			$value = $objectData[$property];

			// Skip empty values
			if ($value === '' || $value === []) {
				continue;
			}

			// Convert to string for comparison
			$searchValue = is_scalar($value) ? (string)$value : '';
			if ($searchValue === '') {
				continue;
			}

			// Use IndexRepository to leverage caching
			$indexData = $this->indexRepository->fetchIndex($collection);
			if (!$indexData instanceof IndexData || $indexData->objects->isEmpty()) {
				continue; // No index yet, no duplicates possible
			}

			// Use Collection's first() method to efficiently find duplicates (stops at first match)
			$duplicate = $indexData->objects->first(function (array $existingObject) use ($property, $searchValue, $object): bool {
				// Skip current object when editing
				if (($existingObject['id'] ?? '') === $object->id) {
					return false;
				}

				// Check if property value matches
				return isset($existingObject[$property]) && (string)$existingObject[$property] === $searchValue;
			});

			if ($duplicate !== null) {
				$label = $propertyConfig['label'] ?? $property;
				throw new \DomainException("{$label} must be unique. The value '{$searchValue}' already exists in this collection.");
			}
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
