<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;

/**
 * Service.
 */
class CollectionFetcher
{
	/** @var array<string, CollectionData|null> Request-level cache for fetched collections */
	private array $cache = [];

	public function __construct(private readonly CollectionRepository $storage)
	{
	}

	/**
	 * Fetch a collection.
	 *
	 * @return CollectionData
	 */
	public function fetchCollection(string $collectionId): ?CollectionData
	{
		// Check request-level cache first
		if (array_key_exists($collectionId, $this->cache)) {
			return $this->cache[$collectionId];
		}

		if ($this->collectionExists($collectionId)) {
			$collection                 = $this->storage->getCollection($collectionId);
			$this->cache[$collectionId] = $collection;

			return $collection;
		}

		if ($this->storage->isReservedCollection($collectionId)) {
			// If the collection is not found or invalid, try to create it
			$this->storage->saveReservedCollection($collectionId);

			$collection                 = $this->storage->getCollection($collectionId);
			$this->cache[$collectionId] = $collection;

			return $collection;
		}

		$this->cache[$collectionId] = null;

		return null;
	}

	public function collectionExists(string $collectionId): bool
	{
		return $this->storage->collectionExists($collectionId);
	}

	/**
	 * Clear the request-level cache.
	 * Useful after saving/updating a collection.
	 */
	public function clearCache(?string $collectionId = null): void
	{
		if ($collectionId !== null) {
			unset($this->cache[$collectionId]);
		} else {
			$this->cache = [];
		}
	}
}
