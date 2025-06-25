<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

final class SchemaFetcher
{

	public function __construct(
		private SchemaRepository $storage,
		private CollectionFetcher $collectionService,
	){}

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
		$collection = $this->collectionService->fetchCollection($collection);

		if ($collection === null) {
			throw new \UnexpectedValueException('Collection for Schema not found: ' . $collection);
		}

		return $this->storage->getSchema($collection->schema);
	}
}
