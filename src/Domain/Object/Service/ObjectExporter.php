<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Schema\Service\CollectionSchemaFetcher;

final class ObjectExporter
{
	public function __construct(
		private IndexRepository $storage,
		private ObjectFetcher $objectFetcher,
		private CollectionSchemaFetcher $schemaFetcher,
	) {
	}

	/** @return array<array<string,mixed>> */
	public function exportAllObjects(string $collection): array
	{
		$objects   = [];
		$objectIds = $this->storage->fetchObjectIds($collection);

		foreach ($objectIds as $id) {
			$object    = $this->objectFetcher->fetchObject($collection, $id);
			$objects[] = $object->toArray();
		}

		return $objects;
	}

	/** @return array<array<int,string>> */
	public function exportAllObjectsForCSv(string $collection): array
	{
		$schema     = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$properties = array_keys($schema->properties);

		$objects   = [$properties];
		$objectIds = $this->storage->fetchObjectIds($collection);

		foreach ($objectIds as $id) {
			$object = $this->objectFetcher->fetchObject($collection, $id)->forCsv();
			$csv    = [];
			foreach ($properties as $property) {
				$csv[] = $object[$property] ?? '';
			}
			$objects[] = $csv;
		}

		return $objects;
	}
}
