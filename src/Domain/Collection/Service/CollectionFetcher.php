<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;

/**
 * Service.
 */
readonly class CollectionFetcher
{
	public function __construct(private CollectionRepository $storage)
	{
	}

	/**
	 * Fetch a collection.
	 *
	 * @return CollectionData
	 */
	public function fetchCollection(string $collectionId): ?CollectionData
	{
		if ($this->collectionExists($collectionId)) {
			return $this->storage->getCollection($collectionId);
		}

		if ($this->storage->isReservedCollection($collectionId)) {
			// If the collection is not found or invalid, try to create it
			$this->storage->saveReservedCollection($collectionId);

			return $this->storage->getCollection($collectionId);
		}

		return null;
	}

	public function collectionExists(string $collectionId): bool
	{
		return $this->storage->collectionExists($collectionId);
	}
}
