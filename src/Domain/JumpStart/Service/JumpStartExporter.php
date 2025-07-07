<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service;

use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LoggerFactory;

final class JumpStartExporter
{
	private CollectionLister $collectionLister;
	private SchemaLister $schemaLister;
	private SchemaFetcher $schemaFetcher;
	private ObjectFetcher $objectFetcher;
	private IndexReader $indexReader;
	private LoggerInterface $logger;


	public function __construct(
		CollectionLister $collectionLister,
		SchemaLister $schemaLister,
		SchemaFetcher $schemaFetcher,
		ObjectFetcher $objectFetcher,
		IndexReader $indexReader,
		LoggerFactory $loggerFactory
	) {
		$this->collectionLister = $collectionLister;
		$this->schemaLister = $schemaLister;
		$this->schemaFetcher = $schemaFetcher;
		$this->objectFetcher = $objectFetcher;
		$this->indexReader = $indexReader;
		$this->logger = $loggerFactory->addFileHandler('jumpstart.log')->createLogger('jumpstart-exporter');
	}

	/**
	 * Export current CMS data to jumpstart definition
	 */
	public function exportCurrentData(string $name = '', string $description = ''): JumpStartData
	{
		$this->logger->info('Starting jumpstart export', [
			'name' => $name ?: 'Current CMS Data',
		]);

		$jumpstart = new JumpStartData(
			$name ?: 'Exported from Current CMS Data',
			$description ?: 'Jumpstart definition generated from existing Total CMS data'
		);

		// Export custom schemas
		$this->exportCustomSchemas($jumpstart);

		// Export collections
		$this->exportCollections($jumpstart);

		// Export objects
		$this->exportObjects($jumpstart);

		$this->logger->info('Completed jumpstart export', [
			'schemas' => count($jumpstart->schemas),
			'reserved_collections' => count($jumpstart->collections['reserved']),
			'custom_collections' => count($jumpstart->collections['custom']),
			'objects' => count($jumpstart->objects)
		]);

		return $jumpstart;
	}

	/**
	 * Export custom schemas (non-reserved schemas)
	 */
	private function exportCustomSchemas(JumpStartData $jumpstart): void
	{
		$allSchemas = $this->schemaLister->listCustomSchemas();

		foreach ($allSchemas as $schema) {
			// Skip reserved schemas
			if (in_array($schema->id, SchemaData::RESERVED_SCHEMAS)) {
				continue;
			}

			// Convert schema to jumpstart format
			$fields = [];
			foreach ($schema->properties as $fieldName => $property) {
				$field = [
					'name' => $fieldName,
					'type' => $property['type'] ?? 'text',
					'label' => $property['label'] ?? $fieldName
				];

				if (isset($property['options']) && !empty($property['options'])) {
					$field['options'] = $property['options'];
				}

				if (in_array($fieldName, $schema->required)) {
					$field['required'] = true;
				}

				$fields[] = $field;
			}

			$jumpstart->addSchema([
				'id' => $schema->id,
				'name' => ucfirst(str_replace('-', ' ', $schema->id)),
				'description' => $schema->description,
				'fields' => $fields
			]);
		}
	}

	/**
	 * Export collections (both reserved and custom)
	 */
	private function exportCollections(JumpStartData $jumpstart): void
	{
		$allCollections = $this->collectionLister->listAllCollections();

		foreach ($allCollections as $collection) {
			if (in_array($collection->schema, SchemaData::RESERVED_SCHEMAS)) {
				// Reserved collection
				$jumpstart->addReservedCollection($collection->schema);
			} else {
				// Custom collection
				$jumpstart->addCustomCollection([
					'id' => $collection->id,
					'name' => $collection->name,
					'schemaId' => $collection->schema
				]);
			}
		}
	}

	/**
	 * Export all objects from all collections using IndexReader
	 */
	private function exportObjects(JumpStartData $jumpstart): void
	{
		$allCollections = $this->collectionLister->listAllCollections();

		foreach ($allCollections as $collection) {
			try {
				$this->logger->info('Exporting objects from collection', [
					'collection' => $collection->id
				]);

				// Use IndexReader to get all object IDs for this collection
				$index = $this->indexReader->fetchIndex($collection->id);

				$objectCount = 0;
				foreach ($index->objects as $object) {
					try {
						$object = $this->objectFetcher->fetchObject($collection->id, $object['id']);

						// Process the object data to normalize image and gallery properties
						$processedData = $this->processObjectData($object, $collection->schema);

						$jumpstart->addObject([
							'collection' => $collection->id,
							'id' => $object->id,
							'data' => $processedData
						]);
						$objectCount++;

					} catch (\Exception $e) {
						$this->logger->warning('Failed to export object', [
							'collection' => $collection->id,
							'object' => $object['id'],
							'error' => $e->getMessage()
						]);
					}
				}

				$this->logger->info('Completed object export for collection', [
					'collection' => $collection->id,
					'objects_exported' => $objectCount
				]);

			} catch (\Exception $e) {
				$this->logger->warning('Failed to export objects from collection', [
					'collection' => $collection->id,
					'error' => $e->getMessage()
				]);
			}
		}
	}

	/**
	 * Process object data to normalize image and gallery properties
	 * @param array<string,mixed> $data
	 * @param string $schemaId
	 * @return array<string,mixed>
	 */
	private function processObjectData(array $data, string $schemaId): array
	{
		try {
			$schema = $this->schemaFetcher->fetchSchema($schemaId);
			$processedData = $data;

			// Process each field according to its schema type
			foreach ($schema->properties as $fieldName => $property) {
				if (isset($data[$fieldName])) {
					$fieldType = $property['type'] ?? '';

					// Normalize image properties to "image"
					if ($fieldType === 'image') {
						$processedData[$fieldName] = 'image';
					}
					// Normalize gallery properties to "gallery"
					elseif ($fieldType === 'gallery') {
						$processedData[$fieldName] = 'gallery';
					}
				}
			}

			return $processedData;
		} catch (\Exception $e) {
			$this->logger->warning('Failed to process object data with schema', [
				'schema' => $schemaId,
				'error' => $e->getMessage()
			]);
			// Return original data if schema processing fails
			return $data;
		}
	}

	/**
	 * Export with collection and object filters
	 * @param array<string> $includeCollections
	 * @param array<string> $excludeCollections
	 */
	public function exportFiltered(
		string $name = '',
		string $description = '',
		array $includeCollections = [],
		array $excludeCollections = []
	): JumpStartData {
		$this->logger->info('Starting filtered jumpstart export', [
			'name' => $name ?: 'Filtered CMS Data',
			'include_collections' => $includeCollections,
			'exclude_collections' => $excludeCollections
		]);

		$jumpstart = new JumpStartData(
			$name ?: 'Filtered Export from CMS Data',
			$description ?: 'Filtered jumpstart definition from existing Total CMS data'
		);

		// Export custom schemas (always include all since collections might depend on them)
		$this->exportCustomSchemas($jumpstart);

		// Export collections with filtering
		$this->exportCollectionsFiltered($jumpstart, $includeCollections, $excludeCollections);

		// Export objects with filtering
		$this->exportObjectsFiltered($jumpstart, $includeCollections, $excludeCollections);

		return $jumpstart;
	}

	/**
	 * Export collections with filtering
	 * @param array<string> $includeCollections
	 * @param array<string> $excludeCollections
	 */
	private function exportCollectionsFiltered(
		JumpStartData $jumpstart,
		array $includeCollections,
		array $excludeCollections
	): void {
		$allCollections = $this->collectionLister->listAllCollections();

		foreach ($allCollections as $collection) {
			// Apply filters
			if (!empty($includeCollections) && !in_array($collection->id, $includeCollections)) {
				continue;
			}
			if (in_array($collection->id, $excludeCollections)) {
				continue;
			}

			if (in_array($collection->schema, $this->defaultCollectionTypes)) {
				$jumpstart->addDefaultCollection($collection->schema);
			} else {
				$jumpstart->addCustomCollection([
					'id' => $collection->id,
					'name' => $collection->name,
					'schemaId' => $collection->schema
				]);
			}
		}
	}

	/**
	 * Export objects with filtering
	 * @param array<string> $includeCollections
	 * @param array<string> $excludeCollections
	 */
	private function exportObjectsFiltered(
		JumpStartData $jumpstart,
		array $includeCollections,
		array $excludeCollections
	): void {
		$allCollections = $this->collectionLister->listAllCollections();

		foreach ($allCollections as $collection) {
			// Apply filters
			if (!empty($includeCollections) && !in_array($collection->id, $includeCollections)) {
				continue;
			}
			if (in_array($collection->id, $excludeCollections)) {
				continue;
			}

			try {
				// Skip object export for now (not yet implemented)
				$this->logger->info('Skipping filtered object export for collection (not yet implemented)', [
					'collection' => $collection->id
				]);
			} catch (\Exception $e) {
				$this->logger->warning('Failed to export objects from collection during filtering', [
					'collection' => $collection->id,
					'error' => $e->getMessage()
				]);
			}
		}
	}
}