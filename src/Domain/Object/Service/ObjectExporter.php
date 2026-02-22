<?php

namespace TotalCMS\Domain\Object\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

readonly class ObjectExporter
{
	private LoggerInterface $logger;

	public function __construct(
		private IndexRepository $storage,
		private ObjectFetcher $objectFetcher,
		private SchemaFetcher $schemaFetcher,
		private IndexFilter $indexFilter,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('object-exporter');
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

	/**
	 * Export all objects for JSON format with error tracking.
	 *
	 * @return array{data: array<array<string,mixed>>, errors: array<string>}
	 */
	public function exportAllObjectsForJson(string $collection): array
	{
		$objects   = [];
		$objectIds = $this->storage->fetchObjectIds($collection);
		$errors    = [];

		foreach ($objectIds as $id) {
			try {
				$object    = $this->objectFetcher->fetchObject($collection, $id);
				$objects[] = $object->toArray();
			} catch (\Throwable $e) {
				// Log the error with details for debugging
				$this->logger->warning('Skipping object during JSON export due to data mismatch', [
					'collection' => $collection,
					'object_id'  => $id,
					'error'      => $e->getMessage(),
					'exception'  => $e::class,
					'hint'       => 'This usually happens when the schema was modified after objects were created. Check if the stored data type matches the current schema.',
				]);
				$errors[] = $id;
			}
		}

		return [
			'data'   => $objects,
			'errors' => $errors,
		];
	}

	/**
	 * Export filtered objects for JSON format with error tracking.
	 *
	 * Uses IndexFilter to apply include/exclude criteria before exporting.
	 *
	 * @param array<string,string> $options Filter options (include, exclude)
	 *
	 * @return array{data: array<array<string,mixed>>, errors: array<string>}
	 */
	public function exportFilteredObjectsForJson(string $collection, array $options): array
	{
		$objectIds = $this->fetchFilteredObjectIds($collection, $options);
		$objects   = [];
		$errors    = [];

		foreach ($objectIds as $id) {
			try {
				$object    = $this->objectFetcher->fetchObject($collection, $id);
				$objects[] = $object->toArray();
			} catch (\Throwable $e) {
				$this->logger->warning('Skipping object during JSON export due to data mismatch', [
					'collection' => $collection,
					'object_id'  => $id,
					'error'      => $e->getMessage(),
					'exception'  => $e::class,
					'hint'       => 'This usually happens when the schema was modified after objects were created. Check if the stored data type matches the current schema.',
				]);
				$errors[] = $id;
			}
		}

		return [
			'data'   => $objects,
			'errors' => $errors,
		];
	}

	/**
	 * Export all objects for CSV format.
	 *
	 * @return array{data: array<array<int,string>>, errors: array<string>}
	 */
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
		$errors    = [];

		foreach ($objectIds as $id) {
			try {
				$object = $this->objectFetcher->fetchObject($collection, $id)->forCsv();
				$csv    = [];
				foreach ($properties as $property) {
					$csv[] = $object[$property] ?? '';
				}
				$objects[] = $csv;
			} catch (\Throwable $e) {
				// Log the error with details for debugging
				$this->logger->warning('Skipping object during CSV export due to data mismatch', [
					'collection' => $collection,
					'object_id'  => $id,
					'error'      => $e->getMessage(),
					'exception'  => $e::class,
					'hint'       => 'This usually happens when the schema was modified after objects were created. Check if the stored data type matches the current schema.',
				]);
				$errors[] = $id;
			}
		}

		return [
			'data'   => $objects,
			'errors' => $errors,
		];
	}

	/**
	 * Export filtered objects for CSV format.
	 *
	 * Uses IndexFilter to apply include/exclude criteria before exporting.
	 *
	 * @param array<string,string> $options Filter options (include, exclude)
	 *
	 * @return array{data: array<array<int,string>>, errors: array<string>}
	 */
	public function exportFilteredObjectsForCsv(string $collection, array $options): array
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

		// Filter out deck properties from CSV export - they're too complex for CSV format
		$properties = array_filter(
			array_keys($schema->properties),
			fn (string $propertyName): bool => !isset($schema->properties[$propertyName]['$ref'])
				|| $schema->properties[$propertyName]['$ref'] !== SchemaData::PROPERTY_TYPE_TO_REF['deck']
		);

		$objects   = [array_values($properties)];
		$objectIds = $this->fetchFilteredObjectIds($collection, $options);
		$errors    = [];

		foreach ($objectIds as $id) {
			try {
				$object = $this->objectFetcher->fetchObject($collection, $id)->forCsv();
				$csv    = [];
				foreach ($properties as $property) {
					$csv[] = $object[$property] ?? '';
				}
				$objects[] = $csv;
			} catch (\Throwable $e) {
				$this->logger->warning('Skipping object during CSV export due to data mismatch', [
					'collection' => $collection,
					'object_id'  => $id,
					'error'      => $e->getMessage(),
					'exception'  => $e::class,
					'hint'       => 'This usually happens when the schema was modified after objects were created. Check if the stored data type matches the current schema.',
				]);
				$errors[] = $id;
			}
		}

		return [
			'data'   => $objects,
			'errors' => $errors,
		];
	}

	/**
	 * Get object IDs filtered by include/exclude criteria.
	 *
	 * @param array<string,string> $options Filter options (include, exclude)
	 *
	 * @return array<string>
	 */
	private function fetchFilteredObjectIds(string $collection, array $options): array
	{
		$filteredObjects = $this->indexFilter->fetchFilteredIndex($collection, $options);

		return array_map(
			fn (array $object): string => (string) ($object['id'] ?? ''),
			$filteredObjects
		);
	}
}
