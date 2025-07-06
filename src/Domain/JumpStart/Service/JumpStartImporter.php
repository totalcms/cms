<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Import\FactoryImporter;
use TotalCMS\Utils\Faker\FakerExtension;
use Exception;
use Psr\Log\LoggerInterface;

final class JumpStartImporter
{
	private CollectionFetcher $collectionFetcher;
	private CollectionSaver $collectionSaver;
	private ObjectSaver $objectSaver;
	private SchemaRepository $schemaRepository;
	private FactoryImporter $factoryImporter;
	private LoggerInterface $logger;
	
	/** @var array<int, array<string, mixed>> */
	private array $results = [];
	
	/** @var array<int, array<string, mixed>> */
	private array $errors = [];
	
	public function __construct(
		CollectionFetcher $collectionFetcher,
		CollectionSaver $collectionSaver,
		ObjectSaver $objectSaver,
		SchemaRepository $schemaRepository,
		FactoryImporter $factoryImporter,
		LoggerInterface $logger
	) {
		$this->collectionFetcher = $collectionFetcher;
		$this->collectionSaver = $collectionSaver;
		$this->objectSaver = $objectSaver;
		$this->schemaRepository = $schemaRepository;
		$this->factoryImporter = $factoryImporter;
		$this->logger = $logger;
	}
	
	/**
	 * Import jumpstart content from definition
	 * @param array<string, mixed> $definition
	 * @return array{success: bool, results: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>, summary: array<string, int>}
	 */
	public function importFromDefinition(array $definition): array
	{
		$this->results = [];
		$this->errors = [];
		
		$this->logger->info('Starting jumpstart import', [
			'name' => $definition['name'] ?? 'Unknown',
			'version' => $definition['version'] ?? 'Unknown'
		]);
		
		// Process schemas first
		if (isset($definition['schemas'])) {
			$this->processSchemas($definition['schemas']);
		}
		
		// Process collections
		if (isset($definition['collections'])) {
			$this->processCollections($definition['collections']);
		}
		
		// Process specific objects
		if (isset($definition['objects'])) {
			$this->processObjects($definition['objects']);
		}
		
		// Process factory generation
		if (isset($definition['factory'])) {
			$this->processFactory($definition['factory']);
		}
		
		return [
			'success' => empty($this->errors),
			'results' => $this->results,
			'errors' => $this->errors,
			'summary' => $this->generateSummary()
		];
	}
	
	/**
	 * @param array<int, array<string, mixed>> $schemas
	 */
	private function processSchemas(array $schemas): void
	{
		foreach ($schemas as $schema) {
			try {
				$schemaData = new SchemaData();
				$schemaData->id = $schema['id'];
				$schemaData->description = $schema['description'] ?? '';
				
				// Convert fields to properties format
				$properties = [];
				$required = [];
				foreach ($schema['fields'] as $field) {
					$properties[$field['name']] = [
						'type' => $field['type'],
						'label' => $field['label'] ?? $field['name'],
						'options' => $field['options'] ?? []
					];
					if ($field['required'] ?? false) {
						$required[] = $field['name'];
					}
				}
				$schemaData->properties = $properties;
				$schemaData->required = $required;
				
				// Save custom schema
				$this->schemaRepository->saveSchema($schemaData);
				
				$this->results[] = [
					'type' => 'schema',
					'action' => 'created',
					'id' => $schema['id'],
					'name' => $schema['name']
				];
				
				$this->logger->info('Created jumpstart schema', ['id' => $schema['id']]);
			} catch (Exception $e) {
				$this->errors[] = [
					'type' => 'schema',
					'id' => $schema['id'],
					'error' => $e->getMessage()
				];
				$this->logger->error('Failed to create jumpstart schema', [
					'id' => $schema['id'],
					'error' => $e->getMessage()
				]);
			}
		}
	}
	
	/**
	 * @param array<string, mixed> $collections
	 */
	private function processCollections(array $collections): void
	{
		// Process default collections
		if (isset($collections['default'])) {
			foreach ($collections['default'] as $collectionType) {
				try {
					$collection = $this->collectionFetcher->fetchCollection($collectionType);
					
					$this->results[] = [
						'type' => 'collection',
						'action' => 'created',
						'id' => $collectionType,
						'name' => ucfirst($collectionType),
						'schema' => 'default'
					];
					
					$this->logger->info('Created default collection via jumpstart', ['type' => $collectionType]);
				} catch (Exception $e) {
					$this->errors[] = [
						'type' => 'collection',
						'id' => $collectionType,
						'error' => $e->getMessage()
					];
					$this->logger->error('Failed to create default collection via jumpstart', [
						'type' => $collectionType,
						'error' => $e->getMessage()
					]);
				}
			}
		}
		
		// Process custom collections
		if (isset($collections['custom'])) {
			foreach ($collections['custom'] as $collectionDef) {
				try {
					$data = [
						'id' => $collectionDef['id'],
						'name' => $collectionDef['name'],
						'schema' => $collectionDef['schemaId']
					];
					
					if (isset($collectionDef['settings'])) {
						$data = array_merge($data, $collectionDef['settings']);
					}
					
					$this->collectionSaver->saveCollection($data);
					
					$this->results[] = [
						'type' => 'collection',
						'action' => 'created',
						'id' => $collectionDef['id'],
						'name' => $collectionDef['name'],
						'schema' => $collectionDef['schemaId']
					];
					
					$this->logger->info('Created custom collection via jumpstart', [
						'id' => $collectionDef['id'],
						'schema' => $collectionDef['schemaId']
					]);
				} catch (Exception $e) {
					$this->errors[] = [
						'type' => 'collection',
						'id' => $collectionDef['id'],
						'error' => $e->getMessage()
					];
					$this->logger->error('Failed to create custom collection via jumpstart', [
						'id' => $collectionDef['id'],
						'error' => $e->getMessage()
					]);
				}
			}
		}
	}
	
	/**
	 * @param array<int, array<string, mixed>> $objects
	 */
	private function processObjects(array $objects): void
	{
		foreach ($objects as $objectDef) {
			$collectionId = $objectDef['collection'];
			$objectId = $objectDef['id'];
			
			try {
				$collection = $this->collectionFetcher->fetchCollection($collectionId);
				
				if ($collection === null) {
					$this->errors[] = [
						'type' => 'object',
						'collection' => $collectionId,
						'id' => $objectId,
						'error' => 'Collection not found'
					];
					continue;
				}
				
				// Process data and handle factory rules for images
				$data = $this->processObjectData($objectDef['data']);
				
				// Add ID to the data
				$data['id'] = $objectId;
				
				// Save the object
				$this->objectSaver->saveObject($collectionId, $data);
				
				$this->results[] = [
					'type' => 'object',
					'action' => 'created',
					'collection' => $collectionId,
					'id' => $objectId
				];
				
				$this->logger->info('Created jumpstart object', [
					'collection' => $collectionId,
					'id' => $objectId
				]);
			} catch (Exception $e) {
				$this->errors[] = [
					'type' => 'object',
					'collection' => $collectionId,
					'id' => $objectId,
					'error' => $e->getMessage()
				];
				$this->logger->error('Failed to create jumpstart object', [
					'collection' => $collectionId,
					'id' => $objectId,
					'error' => $e->getMessage()
				]);
			}
		}
	}
	
	/**
	 * @param array<int, array<string, mixed>> $factoryItems
	 */
	private function processFactory(array $factoryItems): void
	{
		foreach ($factoryItems as $factoryDef) {
			$collectionId = $factoryDef['collection'];
			$factoryData = $factoryDef['data'] ?? [];
			
			// Check if this is a specific ID factory item
			if (isset($factoryDef['id'])) {
				$this->processSpecificFactoryObject($collectionId, $factoryDef['id'], $factoryData);
			} else {
				// Regular bulk factory generation
				$count = $factoryDef['count'] ?? 1;
				$this->processBulkFactoryGeneration($collectionId, $count, $factoryData);
			}
		}
	}
	
	/**
	 * Process a factory item with a specific ID
	 * @param array<string, mixed> $factoryData
	 */
	private function processSpecificFactoryObject(string $collectionId, string $objectId, array $factoryData): void
	{
		try {
			$collection = $this->collectionFetcher->fetchCollection($collectionId);
			
			if ($collection === null) {
				$this->errors[] = [
					'type' => 'factory',
					'collection' => $collectionId,
					'id' => $objectId,
					'error' => 'Collection not found'
				];
				return;
			}
			
			// Generate data using factory rules
			$generatedData = $this->generateFactoryData($factoryData);
			
			// Add ID to the data
			$generatedData['id'] = $objectId;
			
			// Save the object
			$this->objectSaver->saveObject($collectionId, $generatedData);
			
			$this->results[] = [
				'type' => 'factory',
				'action' => 'generated',
				'collection' => $collectionId,
				'id' => $objectId,
				'count' => 1
			];
			
			$this->logger->info('Generated jumpstart factory object with specific ID', [
				'collection' => $collectionId,
				'id' => $objectId
			]);
		} catch (Exception $e) {
			$this->errors[] = [
				'type' => 'factory',
				'collection' => $collectionId,
				'id' => $objectId,
				'error' => $e->getMessage()
			];
			$this->logger->error('Failed to generate jumpstart factory object with specific ID', [
				'collection' => $collectionId,
				'id' => $objectId,
				'error' => $e->getMessage()
			]);
		}
	}
	
	/**
	 * Process bulk factory generation
	 * @param array<string, mixed> $factoryData
	 */
	private function processBulkFactoryGeneration(string $collectionId, int $count, array $factoryData): void
	{
		try {
			$collection = $this->collectionFetcher->fetchCollection($collectionId);
			
			if ($collection === null) {
				$this->errors[] = [
					'type' => 'factory',
					'collection' => $collectionId,
					'error' => 'Collection not found'
				];
				return;
			}
			
			// Get schema factory definitions
			$schemaFactoryDefs = $this->factoryImporter->fetchCollectionFactories($collectionId);
			
			// Merge with custom factory data (custom overrides schema)
			$finalFactoryDefs = array_merge($schemaFactoryDefs, $factoryData);
			
			// Import using FactoryImporter
			$imported = $this->factoryImporter->import($collectionId, $count, $finalFactoryDefs);
			
			$this->results[] = [
				'type' => 'factory',
				'action' => 'generated',
				'collection' => $collectionId,
				'count' => $imported
			];
			
			$this->logger->info('Generated jumpstart factory content', [
				'collection' => $collectionId,
				'count' => $imported
			]);
		} catch (Exception $e) {
			$this->errors[] = [
				'type' => 'factory',
				'collection' => $collectionId,
				'error' => $e->getMessage()
			];
			$this->logger->error('Failed to generate jumpstart factory content', [
				'collection' => $collectionId,
				'error' => $e->getMessage()
			]);
		}
	}
	
	/**
	 * Generate data using factory rules
	 * @param array<string, string> $factoryData
	 * @return array<string, mixed>
	 */
	private function generateFactoryData(array $factoryData): array
	{
		$generatedData = [];
		$faker = \Faker\Factory::create();
		
		// Add custom extensions
		$faker->addProvider(new FakerExtension($faker));
		
		foreach ($factoryData as $field => $rule) {
			try {
				$generatedData[$field] = $this->generateFromFactoryRule($faker, $rule);
			} catch (Exception $e) {
				$this->logger->warning('Failed to generate factory field in jumpstart', [
					'field' => $field,
					'rule' => $rule,
					'error' => $e->getMessage()
				]);
				$generatedData[$field] = $rule; // Use original rule as fallback
			}
		}
		
		return $generatedData;
	}
	
	/**
	 * Process object data and handle factory rules for image fields
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function processObjectData(array $data): array
	{
		$processedData = [];
		$faker = \Faker\Factory::create();
		
		// Add custom extensions
		$faker->addProvider(new FakerExtension($faker));
		
		foreach ($data as $key => $value) {
			if (is_string($value) && $this->isFactoryRule($value)) {
				// Handle factory rule for images
				try {
					$processedData[$key] = $this->generateFromFactoryRule($faker, $value);
				} catch (Exception $e) {
					$this->logger->warning('Failed to generate field from factory rule in jumpstart', [
						'field' => $key,
						'rule' => $value,
						'error' => $e->getMessage()
					]);
					$processedData[$key] = $value; // Use original value as fallback
				}
			} else {
				// Use value as-is
				$processedData[$key] = $value;
			}
		}
		
		return $processedData;
	}
	
	/**
	 * Check if a string looks like a factory rule
	 */
	private function isFactoryRule(string $value): bool
	{
		// Check if it matches the factory rule pattern (method name with optional parameters)
		return preg_match('/^[a-zA-Z][a-zA-Z0-9]*(?:\([^)]*\))?$/', $value) === 1;
	}
	
	/**
	 * Generate content from a factory rule string
	 * @return mixed
	 */
	private function generateFromFactoryRule(\Faker\Generator $faker, string $rule)
	{
		// Parse factory rule (e.g., "imageShapes(800,600)" or "paragraph")
		if (preg_match('/^(\w+)(?:\((.*)\))?$/', $rule, $matches)) {
			$method = $matches[1];
			$args = [];
			
			if (isset($matches[2]) && $matches[2] !== '') {
				// Parse arguments
				$argStrings = str_getcsv($matches[2]);
				foreach ($argStrings as $arg) {
					$arg = trim((string)$arg);
					if (is_numeric($arg)) {
						$args[] = strpos($arg, '.') !== false ? (float)$arg : (int)$arg;
					} elseif ($arg === 'true' || $arg === 'false') {
						$args[] = $arg === 'true';
					} else {
						$args[] = trim($arg, '"\'');
					}
				}
			}
			
			// Call the faker method
			if (method_exists($faker, $method)) {
				return $faker->$method(...$args);
			} else {
				// Default to word if method doesn't exist
				return $faker->word();
			}
		}
		
		// If parsing fails, return the original rule
		return $rule;
	}
	
	/**
	 * @return array<string, int>
	 */
	private function generateSummary(): array
	{
		$summary = [
			'schemas_created' => 0,
			'collections_created' => 0,
			'objects_created' => 0,
			'factory_items_created' => 0,
			'total_errors' => count($this->errors)
		];
		
		foreach ($this->results as $result) {
			switch ($result['type']) {
				case 'schema':
					$summary['schemas_created']++;
					break;
				case 'collection':
					$summary['collections_created']++;
					break;
				case 'object':
					$summary['objects_created']++;
					break;
				case 'factory':
					$summary['factory_items_created'] += $result['count'] ?? 1;
					break;
			}
		}
		
		return $summary;
	}
}