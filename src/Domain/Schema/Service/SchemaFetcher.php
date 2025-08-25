<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

final readonly class SchemaFetcher
{
	public function __construct(
		private SchemaRepository $storage,
		private CollectionRepository $collectionRepository,
	) {
	}

	public function fetchSchema(string $id): SchemaData
	{
		return $this->storage->getSchema($id);
	}

	public function schemaExists(string $id): bool
	{
		return $this->storage->schemaExists($id);
	}

	public function fetchSchemaForCollection(string $collection): SchemaData
	{
		$collectionData = $this->collectionRepository->fetchCollection($collection);

		if (!$collectionData instanceof CollectionData) {
			throw new \UnexpectedValueException('Collection for Schema not found: ' . $collection);
		}

		return $this->storage->getSchema($collectionData->schema);
	}
}
