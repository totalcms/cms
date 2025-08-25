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
final class IndexRepository extends StorageRepository
{
	private const INDEX_FILE = '.index.json';

	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly CacheManager $cacheManager,
	) {
		parent::__construct($filesystem);
	}

	/**
     * get the index.
     *
     *
     * @SuppressWarnings("PHPMD.ElseExpression")
     *
     */
    public function fetchIndex(string $collection): ?IndexData
	{
		// Try cache first (Redis preferred for fast index access)
		$cacheKey = "index:{$collection}";
		$cached   = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			// Reconstruct IndexData from cached array of objects
			try {
				return new IndexData($cached);
			} catch (\Exception) {
				// Cache contains invalid data, fall through to filesystem
			}
		}

		// Cache miss - fetch from filesystem
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
				// Cache non-empty indexes
				$this->cacheManager->storeComputedData($cacheKey, $indexData->objects->toArray(), CacheManager::TTL_INDEX_DATA);
			}
		}

		return $indexData;
	}

	/**
     * Get an array of object IDs in.
     *
     *
     * @SuppressWarnings("PHPMD.ElseExpression")
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
		$files = $this->filesystem->listFiles($collection);

		// Filter for object json files
		$files = array_filter($files, fn (string $path): bool => str_ends_with($path, StorageRepository::FILE_EXT) && !str_starts_with($path, '.'));

		$objectIds = array_map(fn (string $path): string => basename($path, StorageRepository::FILE_EXT), $files);

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
     * save the index.
     *
     *
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
	 * Invalidate index-related caches for a collection.
	 */
	private function invalidateIndexCache(string $collection): void
	{
		// Clear index data cache
		$this->cacheManager->clearComputedData("index:{$collection}");

		// Clear object IDs cache (index changes usually mean object list changed)
		$this->cacheManager->clearComputedData("object_ids:{$collection}");
	}

	private function buildIndexPath(string $collection): string
	{
		return PathUtils::buildPath(collection: $collection, filename: self::INDEX_FILE);
	}
}
