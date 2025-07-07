<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Schema\Service\SchemaValidator;
use TotalCMS\Domain\Import\FactoryImporter;
use TotalCMS\Utils\Faker\FakerExtension;
use TotalCMS\Factory\LoggerFactory;
use Exception;
use Psr\Log\LoggerInterface;

final class JumpStartImporter
{
	private LoggerInterface $logger;

	/** @var array<int, array<string, mixed>> */
	private array $results = [];

	/** @var array<int, array<string, mixed>> */
	private array $errors = [];

	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private CollectionSaver $collectionSaver,
		private ObjectSaver $objectSaver,
		private SchemaSaver $schemaSaver,
		private FactoryImporter $factoryImporter,
		LoggerFactory $loggerFactory
	) {
		$this->logger = $loggerFactory->addFileHandler('jumpstart.log')->createLogger('jumpstart-importer');
	}

	/**
	 * Import jumpstart content from file
	 * @param string $filePath Path to the jumpstart JSON file
	 * @return array{success: bool, results: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>, summary: array<string, int>}
	 */
	public function importFromFile(string $filePath): array
	{
		if (!file_exists($filePath)) {
			throw new Exception("Jumpstart file not found: {$filePath}");
		}

		$content = file_get_contents($filePath);
		if ($content === false) {
			throw new Exception("Failed to read jumpstart file: {$filePath}");
		}

		$definition = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception("Invalid JSON in jumpstart file: " . json_last_error_msg());
		}

		return $this->importFromDefinition($definition);
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
			'name'    => $definition['name'] ?? 'Unknown',
			'version' => $definition['version'] ?? 'Unknown'
		]);

		// Need to process in this order to ensure dependencies are met
		if (isset($definition['schemas'])) {
			$this->processSchemas($definition['schemas']);
		}
		if (isset($definition['collections'])) {
			$this->processCollections($definition['collections']);
		}
		// if (isset($definition['objects'])) {
		// 	$this->processObjects($definition['objects']);
		// }
		// if (isset($definition['factory'])) {
		// 	$this->processFactory($definition['factory']);
		// }

		return [
			'success' => empty($this->errors),
			'results' => $this->results,
			'errors'  => $this->errors,
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
				// Skip reserved schemas
				if (in_array($schema['id'] ?? '', SchemaData::RESERVED_SCHEMAS)) {
					$this->logger->info('Skipping reserved schema', ['id' => $schema['id']]);
					continue;
				}
				$schemaData      = $this->schemaSaver->saveSchema($schema);
				$this->results[] = [
					'type'   => 'schema',
					'action' => 'created',
					'id'     => $schemaData->id,
				];

				$this->logger->info('Created jumpstart schema', ['id' => $schemaData->id]);

			} catch (Exception $e) {
				$this->errors[] = [
					'type' => 'schema',
					'id' => $schema['id'] ?? 'unknown',
					'error' => $e->getMessage()
				];
				$this->logger->error('Failed to create jumpstart schema', [
					'id' => $schema['id'] ?? 'unknown',
					'error' => $e->getMessage()
				]);
			}
		}
	}

	/** @param array<string, mixed> $collections */
	private function processCollections(array $collections): void
	{
		// Process reserved collections
		if (isset($collections['reserved'])) {
			foreach ($collections['reserved'] as $collectionType) {
				$this->createReservedCollection($collectionType);
			}
		}

		// Process custom collections
		if (isset($collections['custom'])) {
			foreach ($collections['custom'] as $collectionDef) {
				$this->createCustomCollection($collectionDef);
			}
		}
	}

	private function createReservedCollection(string $collectionType): void
	{
		try {
			// Reserved collections are auto-created by fetching them
			$collection = $this->collectionFetcher->fetchCollection($collectionType);

			if ($collection === null) {
				throw new Exception("Error creating Reserved Collection: {$collectionType}");
			}

			$this->results[] = [
				'type'   => 'collection',
				'action' => 'created',
				'id'     => $collection->id,
				'schema' => $collection->schema,
			];

			$this->logger->info('Created reserved collection via jumpstart', ['type' => $collectionType]);
		} catch (Exception $e) {
			$this->errors[] = [
				'type'  => 'collection',
				'id'    => $collectionType,
				'error' => $e->getMessage()
			];
			$this->logger->error('Failed to create reserved collection via jumpstart', [
				'type'  => $collectionType,
				'error' => $e->getMessage()
			]);
		}
	}

	/** @param array<string, mixed> $collectionDef */
	private function createCustomCollection(array $collectionDef): void
	{
		try {
			// Save the collection
			$collection = $this->collectionSaver->saveCollection($collectionDef);

			$this->results[] = [
				'type'   => 'collection',
				'action' => 'created',
				'id'     => $collection->id,
				'schema' => $collection->schema,
			];

			$this->logger->info('Created custom collection via jumpstart', [
				'id'     => $collection->id,
				'schema' => $collection->schema
			]);
		} catch (Exception $e) {
			$this->errors[] = [
				'type'  => 'collection',
				'id'    => $collectionDef['id'] ?? 'unknown',
				'error' => $e->getMessage()
			];
			$this->logger->error('Failed to create custom collection via jumpstart', [
				'id'    => $collectionDef['id'] ?? 'unknown',
				'error' => $e->getMessage()
			]);
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