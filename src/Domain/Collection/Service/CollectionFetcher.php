<?php

declare(strict_types=1);

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

		// Disabled: Auto-creating reserved collections caused issues where typing
		// a reserved collection ID (like "blog") in the new collection form would
		// auto-create it before the user could finish, showing "already exists" error.
		// if ($this->storage->isReservedCollection($collectionId)) {
		// 	// If the collection is not found or invalid, try to create it
		// 	$this->storage->saveReservedCollection($collectionId);
		//
		// 	$collection                 = $this->storage->getCollection($collectionId);
		// 	$this->cache[$collectionId] = $collection;
		//
		// 	return $collection;
		// }

		$this->cache[$collectionId] = null;

		return null;
	}

	public function collectionExists(string $collectionId): bool
	{
		return $this->storage->collectionExists($collectionId);
	}

	/**
	 * Fetch a reserved collection, creating it if it doesn't exist.
	 * Use this for explicit creation (e.g., project setup), not for general fetching.
	 */
	public function fetchOrCreateReserved(string $collectionId): ?CollectionData
	{
		// If it already exists, just fetch it
		if ($this->collectionExists($collectionId)) {
			return $this->fetchCollection($collectionId);
		}

		// Only create if it's a reserved collection
		if (!$this->storage->isReservedCollection($collectionId)) {
			return null;
		}

		$this->storage->saveReservedCollection($collectionId);
		$this->clearCache($collectionId);

		return $this->fetchCollection($collectionId);
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
