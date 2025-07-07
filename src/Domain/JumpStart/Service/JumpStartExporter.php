<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service;

use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LoggerFactory;

final class JumpStartExporter
{
	private CollectionRepository $collectionRepository;
	private SchemaRepository $schemaRepository;
	private LoggerInterface $logger;
	
	/** @var array<string> */
	private array $defaultCollectionTypes = [
		'blog', 'image', 'gallery', 'text', 'feed', 'form', 'file', 
		'depot', 'toggle', 'navigation', 'snippet', 'template'
	];
	
	public function __construct(
		CollectionRepository $collectionRepository,
		SchemaRepository $schemaRepository,
		LoggerFactory $loggerFactory
	) {
		$this->collectionRepository = $collectionRepository;
		$this->schemaRepository = $schemaRepository;
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
			'default_collections' => count($jumpstart->collections['default']),
			'custom_collections' => count($jumpstart->collections['custom']),
			'objects' => count($jumpstart->objects)
		]);
		
		return $jumpstart;
	}
	
	/**
	 * Export custom schemas (non-default schemas)
	 */
	private function exportCustomSchemas(JumpStartData $jumpstart): void
	{
		$allSchemas = $this->schemaRepository->listCustomSchemas();
		
		foreach ($allSchemas as $schema) {
			// Skip default/reserved schemas
			if (in_array($schema->id, $this->defaultCollectionTypes)) {
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
	 * Export collections (both default and custom)
	 */
	private function exportCollections(JumpStartData $jumpstart): void
	{
		$allCollections = $this->collectionRepository->listAllCollections();
		
		foreach ($allCollections as $collection) {
			if (in_array($collection->schema, $this->defaultCollectionTypes)) {
				// Default collection
				$jumpstart->addDefaultCollection($collection->schema);
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
	 * Export all objects from all collections
	 * Note: ObjectRepository doesn't have a method to get all objects, so we skip this for now
	 * This could be implemented by reading the collection data files directly
	 */
	private function exportObjects(JumpStartData $jumpstart): void
	{
		$allCollections = $this->collectionRepository->listAllCollections();
		
		foreach ($allCollections as $collection) {
			try {
				// For now, we'll skip object export since ObjectRepository doesn't have a findAll method
				// This could be implemented by reading the collection data files directly from the filesystem
				$this->logger->info('Skipping object export for collection (not yet implemented)', [
					'collection' => $collection->id
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
		$allCollections = $this->collectionRepository->listAllCollections();
		
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
		$allCollections = $this->collectionRepository->listAllCollections();
		
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