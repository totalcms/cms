<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

readonly class ObjectExporter
{
	public function __construct(
		private IndexRepository $storage,
		private ObjectFetcher $objectFetcher,
		private SchemaFetcher $schemaFetcher,
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
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

		// Filter out deck properties from CSV export - they're too complex for CSV format
		$properties = array_filter(
			array_keys($schema->properties),
			fn (string $propertyName): bool => !isset($schema->properties[$propertyName]['$ref'])
				|| $schema->properties[$propertyName]['$ref'] !== SchemaData::PROPERTY_TYPE_TO_REF['deck']
		);

		$objects   = [array_values($properties)];
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
