<?php

namespace TotalCMS\Domain\Index\Repository;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Repository.
 */
class IndexRepository extends StorageRepository
{
	private const INDEX_FILE = '.index.json';

	/**
	 * Request-level memoization cache for indexes.
	 * Stores IndexData objects to avoid multiple cache/filesystem reads within a single request.
	 *
	 * @var array<string,IndexData>
	 */
	private array $requestCache = [];

	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly CacheManager $cacheManager,
	) {
		parent::__construct($filesystem);
	}

	/**
	 * get the index.
	 */
	public function fetchIndex(string $collection): ?IndexData
	{
		$cacheKey = "index:{$collection}";

		// Try request-level cache first (fastest - in-memory)
		if (isset($this->requestCache[$cacheKey])) {
			return $this->requestCache[$cacheKey];
		}

		// Try persistent cache second (Redis/APCu/etc - fast)
		$cached = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			// Reconstruct IndexData from cached array of objects
			try {
				$indexData = new IndexData($cached);
				// Store in request cache for subsequent access in this request
				$this->requestCache[$cacheKey] = $indexData;

				return $indexData;
			} catch (\Exception) {
				// Cache contains invalid data, fall through to filesystem
			}
		}

		// Cache miss - fetch from filesystem (slowest)
		$indexFile = $this->buildIndexPath($collection);

		if (!$this->filesystem->fileExists($indexFile)) {
			return null;
		}

		$indexData = $this->fetchAndDeserialize($indexFile, IndexData::class);

		// Cache the index objects array for 30 minutes (indexes change when objects are added/removed)
		if ($indexData !== null) {
			if ($indexData->objects->isEmpty()) {
				// Clear cache if index is empty to prevent serving stale data
				$this->cacheManager->clearComputedData($cacheKey);
			} else {
				// Cache non-empty indexes in both caches
				$this->cacheManager->storeComputedData($cacheKey, $indexData->objects->toArray(), CacheManager::TTL_INDEX_DATA);
				$this->requestCache[$cacheKey] = $indexData;
			}
		}

		return $indexData;
	}

	/**
	 * Get an array of object IDs in.
	 *
	 * @return array<string>
	 */
	public function fetchObjectIds(string $collection): array
	{
		// Try cache first (Redis preferred for fast access)
		$cacheKey = "object_ids:{$collection}";
		$cached   = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			return $cached;
		}

		// Cache miss - scan filesystem (expensive!)
		$objectIds = $this->objectIdsFromFiles($collection);

		if ($objectIds === []) {
			// Clear cache if no objects to prevent serving stale data
			$this->cacheManager->clearComputedData($cacheKey);
		} else {
			// Cache object IDs for 15 minutes (changes when objects are added/removed)
			$this->cacheManager->storeComputedData($cacheKey, $objectIds, CacheManager::TTL_OBJECT_IDS);
		}

		return $objectIds;
	}

	/**
	 * Get an array of object IDs directly from disk, bypassing all caches.
	 * Used by index rebuilding to ensure accurate filesystem state.
	 *
	 * @return array<string>
	 */
	public function fetchObjectIdsFromDisk(string $collection): array
	{
		$objectIds = $this->objectIdsFromFiles($collection);

		// Update cache with fresh filesystem data
		$cacheKey = "object_ids:{$collection}";
		if ($objectIds === []) {
			$this->cacheManager->clearComputedData($cacheKey);
		} else {
			$this->cacheManager->storeComputedData($cacheKey, $objectIds, CacheManager::TTL_OBJECT_IDS);
		}

		return $objectIds;
	}

	/**
	 * save the index.
	 */
	public function saveIndex(string $collection, IndexData $index): void
	{
		$indexFile  = $this->buildIndexPath($collection);
		$indexJSON  = $this->serializer->serialize($index, 'json'); // no pretty print on purpose

		$this->filesystem->write($indexFile, $indexJSON);

		// Invalidate cached index data when saved
		$this->invalidateIndexCache($collection);
	}

	/**
	 * Open a streaming index writer for memory-efficient index building.
	 * Uses php://temp which keeps data in memory until 2MB, then spills to disk.
	 *
	 * @return resource File handle for streaming writes
	 */
	public function openIndexStream(string $collection)
	{
		// Use php://temp for efficient memory/disk hybrid streaming
		$handle = fopen('php://temp/maxmemory:2097152', 'w+');
		if ($handle === false) {
			throw new \RuntimeException('Failed to open temp stream for index writing');
		}

		// Write opening bracket
		fwrite($handle, '{"objects":[');

		return $handle;
	}

	/**
	 * Write a single index entry to the streaming index.
	 *
	 * @param resource $handle
	 * @param array<string,mixed> $entry
	 */
	public function writeIndexEntry($handle, array $entry, bool $isFirst): void
	{
		if (!$isFirst) {
			fwrite($handle, ',');
		}
		$json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode index entry: ' . json_last_error_msg());
		}
		fwrite($handle, $json);
	}

	/**
	 * Close the streaming index writer and save to filesystem.
	 *
	 * @param resource $handle
	 */
	public function closeIndexStream($handle, string $collection): void
	{
		// Write closing bracket
		fwrite($handle, ']}');

		// Rewind stream to beginning for reading
		rewind($handle);

		// Write stream to filesystem via Flysystem
		$indexFile = $this->buildIndexPath($collection);
		$this->filesystem->flysystem()->writeStream($indexFile, $handle);

		fclose($handle);

		// Invalidate caches
		$this->invalidateIndexCache($collection);
	}

	/**
	 * Invalidate index-related caches for a collection.
	 */
	private function invalidateIndexCache(string $collection): void
	{
		// Clear request cache (in-memory)
		unset($this->requestCache["index:{$collection}"]);

		// Clear persistent index data cache
		$this->cacheManager->clearComputedData("index:{$collection}");

		// Clear object IDs cache (index changes usually mean object list changed)
		$this->cacheManager->clearComputedData("object_ids:{$collection}");
	}

	private function buildIndexPath(string $collection): string
	{
		return PathUtils::buildPath(collection: $collection, filename: self::INDEX_FILE);
	}

	/**
	 * Object ids from the files in a collection directory: `{id}.json` and
	 * `{id}.md`, each id once, dot-prefixed files skipped.
	 *
	 * @return array<string>
	 */
	private function objectIdsFromFiles(string $collection): array
	{
		$ids = [];
		foreach ($this->filesystem->listFiles($collection) as $path) {
			$name = basename($path);
			if (str_starts_with($name, '.')) {
				continue;
			}
			foreach (['.json', '.md'] as $ext) {
				if (str_ends_with($name, $ext)) {
					// Keyed on the id to dedupe a .json/.md pair for the same
					// id down to one entry. PHP casts a numeric-string key
					// like "1" to the int 1, so array_keys() alone would hand
					// back an int here — cast back to string on the way out.
					$ids[substr($name, 0, -strlen($ext))] = true;
					break;
				}
			}
		}

		return array_map('strval', array_keys($ids));
	}
}
