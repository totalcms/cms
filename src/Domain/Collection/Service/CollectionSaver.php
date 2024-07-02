<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;

/**
 * Service.
 */
final class CollectionSaver
{
	private CollectionRepository $storage;
	private CollectionFactory $factory;

	public function __construct(CollectionRepository $storage, CollectionFactory $factory)
	{
		$this->storage   = $storage;
		$this->factory   = $factory;
	}

	/**
	 * Save Collection data.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @throws \DomainException
	 * @throws \UnexpectedValueException
	 *
	 * @return CollectionData
	 */
	public function saveCollection(array $data): CollectionData
	{
		$collection = $this->factory->generateCollection($data);

		if ($this->storage->collectionExists($collection->id)) {
			throw new \DomainException(sprintf('Collection with id %s already exists', $collection->id));
		}

		$this->storage->saveCollection($collection);

		return $collection;
	}

	/**
	 * update Collection data.
	 *
	 * @param string $collectionId
	 * @param array<string,mixed> $data The collection data to save
	 *
	 * @throws \UnexpectedValueException
	 *
	 * @return CollectionData
	 */
	public function updateCollection(string $collectionId, array $data): CollectionData
	{
		$collection = $this->factory->generateCollection($data);

		if ($collection->id !== $collectionId) {
			throw new \UnexpectedValueException('Invalid Collection data provided. Does not match collection ID.', 1);
		}

		$this->storage->saveCollection($collection);

		return $collection;
	}

	/**
	 * update Collection data.
	 *
	 * @param string $collectionId
	 * @param array<string,mixed> $patch The collection data to patch
	 *
	 * @throws \UnexpectedValueException
	 *
	 * @return CollectionData
	 */
	public function patchCollection(string $collectionId, array $patch): CollectionData
	{
		$collection = $this->storage->fetchCollection($collectionId);

		if ($collection === null) {
			throw new \UnexpectedValueException(sprintf('Error fetching Collection with id %s', $collectionId));
		}

		$mergedCollection = array_merge($collection->toArray(), $patch);

		return $this->updateCollection($collectionId, $mergedCollection);
	}
}
