<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;

final class CollectionLister
{
	private CollectionRepository $storage;

	public function __construct(CollectionRepository $storage)
	{
		$this->storage = $storage;
	}

	/** @return array<CollectionData> */
	public function listAllCollections(): array
	{
		return $this->storage->listAllCollections();
	}

	/** @return array<CollectionData> */
	public function listCustomCollections(): array
	{
		$collections = $this->storage->listAllCollections();

		return array_filter($collections, function (CollectionData $collection) {
			return $this->storage->isReservedCollection($collection->id) === false;
		});
	}

	/** @return array<CollectionData> */
	public function listCollectionsWithSchema(string $schemaId): array
	{
		$collections = $this->storage->listAllCollections();

		return array_filter($collections, function (CollectionData $collection) use ($schemaId) {
			return $collection->schema === $schemaId;
		});
	}
}
