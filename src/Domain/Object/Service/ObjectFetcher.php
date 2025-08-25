<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

/**
 * Service.
 */
final readonly class ObjectFetcher
{
	public function __construct(private ObjectRepository $storage)
	{
	}

	/**
	 * get a collection object.
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
	 * get a collection object.
	 */
	public function existsObject(string $collection, string $id): bool
	{
		return $this->storage->existsObject($collection, $id);
	}
}
