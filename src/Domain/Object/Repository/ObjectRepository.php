<?php

namespace TotalCMS\Domain\Object\Repository;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Object\Service\ObjectFileCodec;
use TotalCMS\Domain\Property\Service\ExternalFieldStore;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
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

	/** @var array<string,?string> collection id → body property, for this request */
	private array $bodyPropertyCache = [];

	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly ObjectFactory $factory,
		private readonly SchemaValidator $validator,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CacheManager $cacheManager,
		private readonly SchemaFetcher $schemaFetcher,
		private readonly IndexRepository $indexRepository,
		private readonly ExternalFieldStore $externalFields,
		private readonly ObjectFileCodec $codec,
		private readonly LoggerInterface $logger,
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

		// Externalize `external: true` code fields to sidecar files, then blank
		// them in the canonical object JSON so the value lives in one place.
		$persisted = $this->externalFields->persist($collection, $object);

		$objectFile = $this->writePath($collection, $object->id);
		$data       = $object->toArray();
		foreach ($persisted as $name) {
			$data[$name] = '';
		}
		$this->filesystem->write($objectFile, $this->codec->encode($data, $this->format($collection), $this->bodyProperty($collection)));

		// Invalidate object cache when saved (data has changed)
		$cacheKey = "object:{$collection}:{$object->id}";
		$this->invalidateObjectCache($cacheKey, $collection);
	}

	public function existsObject(string $collection, string $id): bool
	{
		return $this->objectPath($collection, $id) !== null;
	}

	public function fetchObject(string $collection, string $id): ?ObjectData
	{
		$cacheKey = "object:{$collection}:{$id}";

		// Try request-level cache first (fastest - in-memory)
		if (isset($this->requestCache[$cacheKey])) {
			return $this->buildObject($collection, $this->requestCache[$cacheKey]);
		}

		// Try persistent cache second (Redis/APCu/etc - fast)
		// Note: CacheManager handles disableCache() check internally
		$cached = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			// Store in request cache for subsequent access in this request
			$this->requestCache[$cacheKey] = $cached;

			return $this->buildObject($collection, $cached);
		}

		// Cache miss - fetch from filesystem (slowest)
		$contents = $this->readContents($collection, $id);
		if ($contents !== null) {
			$this->cacheManager->storeComputedData($cacheKey, $contents, CacheManager::TTL_OBJECT_DATA);
			$this->requestCache[$cacheKey] = $contents;

			return $this->buildObject($collection, $contents);
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
		$contents = $this->readContents($collection, $id);

		return $contents === null ? null : $this->buildObject($collection, $contents);
	}

	/**
	 * Build an ObjectData from raw JSON contents, then hydrate any external
	 * (file-backed) fields from their sidecar files.
	 *
	 * @param array<string,mixed> $contents
	 */
	private function buildObject(string $collection, array $contents): ObjectData
	{
		$object = $this->factory->generateObject($collection, $contents);
		$this->externalFields->hydrate($collection, $object);

		return $object;
	}

	public function deleteObject(string $collection, string $id): bool
	{
		$filesPath = $this->buildObjectFilesPath($collection, $id);

		$this->filesystem->deleteDirectory($filesPath);
		$deleted = false;
		foreach ([CollectionData::FORMAT_JSON, CollectionData::FORMAT_MARKDOWN] as $format) {
			$path = PathUtils::buildPath(collection: $collection, filename: $id . $this->codec->extension($format));
			if ($this->filesystem->fileExists($path)) {
				$deleted = $this->filesystem->delete($path) || $deleted;
			}
		}

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

	/**
	 * Clear every cached copy of every object in a collection: the
	 * request-level memo on this instance AND the persistent backends. Used
	 * after an index rebuild (`tcms repair:index`) so a hand-edited file's
	 * new contents are what fetchObject() returns next, not whatever was
	 * already warmed into cache from before the edit — clearCollectionIndex()
	 * alone only invalidates the index/object-id caches, not individual
	 * objects.
	 */
	public function clearCollectionCache(string $collection): void
	{
		$prefix = "object:{$collection}:";
		foreach (array_keys($this->requestCache) as $key) {
			if (str_starts_with($key, $prefix)) {
				unset($this->requestCache[$key]);
			}
		}

		$this->cacheManager->clearCollectionObjects($collection);
	}

	/**
	 * Delete exactly the given object file (used by the format converter to
	 * remove the superseded file). Unlike deleteObject(), this never touches
	 * the object's assets folder.
	 */
	public function deleteObjectFile(string $path): bool
	{
		$collection = dirname($path);
		$id         = pathinfo($path, PATHINFO_FILENAME);

		$deleted = $this->filesystem->delete($path);
		if ($deleted) {
			$cacheKey = "object:{$collection}:{$id}";
			$this->invalidateObjectCache($cacheKey, $collection);
		}

		return $deleted;
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
		// Use SchemaFetcher to get flattened schema (with inheritance resolved)
		$schema     = $this->schemaFetcher->fetchSchema($collectionInfo->schema);
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

	/**
	 * Not memoized here — `CollectionFetcher` already holds the request-level
	 * cache and is the single invalidation point (`clearCache()`). Memoizing
	 * a second time in this class would let a format flip mid-request (e.g.
	 * the markdown converter) go unnoticed by `writePath()`/`objectPath()`.
	 */
	private function format(string $collection): string
	{
		$info = $this->collectionFetcher->fetchCollection($collection);

		return $info instanceof CollectionData ? $info->format : CollectionData::FORMAT_JSON;
	}

	private function bodyProperty(string $collection): ?string
	{
		if (!array_key_exists($collection, $this->bodyPropertyCache)) {
			try {
				$schema                               = $this->schemaFetcher->fetchSchemaForCollection($collection);
				$this->bodyPropertyCache[$collection] = $this->codec->bodyProperty($schema->properties);
			} catch (\Throwable) {
				$this->bodyPropertyCache[$collection] = null;
			}
		}

		return $this->bodyPropertyCache[$collection];
	}

	/** The path a write goes to: always the collection's format. */
	private function writePath(string $collection, string $id): string
	{
		return PathUtils::buildPath(collection: $collection, filename: $id . $this->codec->extension($this->format($collection)));
	}

	/**
	 * The file the object actually has, or null. The collection's format is
	 * tried first, then the other extension, so a half-converted collection
	 * and a file dropped in by hand both load.
	 */
	public function objectPath(string $collection, string $id): ?string
	{
		$preferred = $this->format($collection);
		$other     = $preferred === CollectionData::FORMAT_MARKDOWN ? CollectionData::FORMAT_JSON : CollectionData::FORMAT_MARKDOWN;
		foreach ([$preferred, $other] as $format) {
			$path = PathUtils::buildPath(collection: $collection, filename: $id . $this->codec->extension($format));
			if ($this->filesystem->fileExists($path)) {
				return $path;
			}
		}

		return null;
	}

	/** Format of an existing file, from its extension. */
	private function formatOf(string $path): string
	{
		return str_ends_with($path, '.md') ? CollectionData::FORMAT_MARKDOWN : CollectionData::FORMAT_JSON;
	}

	/** @return array<string,mixed>|null null when the file is missing or unparseable */
	private function readContents(string $collection, string $id): ?array
	{
		$path = $this->objectPath($collection, $id);
		if ($path === null) {
			return null;
		}
		try {
			$data = $this->codec->decode($this->filesystem->read($path), $this->formatOf($path), $this->bodyProperty($collection));
			// The file name is the object's identity, not whatever an `id:`
			// line in hand-edited frontmatter says (it's optional, and wrong
			// or absent values must not matter). Applies to both formats so a
			// stray/mismatched `id` in a JSON file can't confuse it either.
			$data['id'] = $id;

			return $data;
		} catch (\UnexpectedValueException $e) {
			$this->logger->warning('Skipping unreadable object file', [
				'collection' => $collection,
				'id'         => $id,
				'path'       => $path,
				'error'      => $e->getMessage(),
			]);

			return null;
		}
	}
}
