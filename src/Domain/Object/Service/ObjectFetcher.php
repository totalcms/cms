<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

/**
 * Service.
 */
readonly class ObjectFetcher
{
	public function __construct(private ObjectRepository $storage)
	{
	}

	/**
	 * Get a collection object.
	 */
	public function fetchObject(string $collection, string $id): ObjectData
	{
		$object = $this->storage->fetchObject($collection, $id);

		if (!$object instanceof ObjectData) {
			throw new \UnexpectedValueException("Unable to fetch object $collection/$id");
		}

		return $object;
	}

	/**
	 * Get a collection object directly from disk, bypassing all caches.
	 * Use for bulk operations like index building where fresh data is required.
	 */
	public function fetchObjectFromDisk(string $collection, string $id): ObjectData
	{
		$object = $this->storage->fetchObjectFromDisk($collection, $id);

		if (!$object instanceof ObjectData) {
			throw new \UnexpectedValueException("Unable to fetch object $collection/$id");
		}

		return $object;
	}

	/**
	 * get a collection object.
	 */
	public function existsObject(string $collection, string $id): bool
	{
		return $this->storage->existsObject($collection, $id);
	}

	/**
	 * Clear every cached copy of every object in a collection (request-level
	 * and persistent). Used by IndexBuilder after a rebuild, so a hand-edited
	 * file's new contents are what the next fetchObject() call returns.
	 */
	public function clearCollectionCache(string $collection): void
	{
		$this->storage->clearCollectionCache($collection);
	}
}
