<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

/**
 * Service.
 */
final readonly class ObjectFetcher
{
	private ObjectRepository $storage;

	public function __construct(ObjectRepository $storage)
	{
		$this->storage = $storage;
	}

	/**
	 * get a collection object.
	 *
	 * @param string $collection
	 * @param string $id
	 *
	 * @return ObjectData
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
	 *
	 * @param string $collection
	 * @param string $id
	 *
	 * @return bool
	 */
	public function existsObject(string $collection, string $id): bool
	{
		return $this->storage->existsObject($collection, $id);
	}
}
