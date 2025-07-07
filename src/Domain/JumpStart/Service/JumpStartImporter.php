<?php

declare(strict_types = 1);

namespace TotalCMS\Domain\JumpStart\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Factory\LoggerFactory;

/** @SuppressWarnings("PHPMD.ExcessiveClassComplexity") */
final class JumpStartImporter
{
	private const DEMO_JUMPSTART_FILE = __DIR__ . '/../../../../resources/jumpstart/demo.json';

	private LoggerInterface $logger;

	/** @var array<int, string> */
	private array $results = [];

	/** @var array<int, string> */
	private array $errors = [];

	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private CollectionSaver $collectionSaver,
		private ObjectFetcher $objectFetcher,
		private ObjectSaver $objectSaver,
		private SchemaSaver $schemaSaver,
		private FactoryImporter $factoryImporter,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('jumpstart.log')->createLogger('jumpstart-importer');
	}

	private function addError(string $message): void
	{
		$this->errors[] = $message;
		$this->logger->error($message);
	}

	private function addResult(string $message): void
	{
		$this->results[] = $message;
		$this->logger->info($message);
	}

	/**
	 * @param string $filePath Path to the jumpstart JSON file
	 *
	 * @return array{success:bool,results:array<int,string>,errors:array<int,string>,summary:array<string,int>}
	 */
	public function importFromFile(string $filePath): array
	{
		if (!file_exists($filePath)) {
			throw new \Exception("Jumpstart file not found: {$filePath}");
		}

		$content = file_get_contents($filePath);
		if ($content === false) {
			throw new \Exception("Failed to read jumpstart file: {$filePath}");
		}

		$definition = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \Exception('Invalid JSON in jumpstart file: ' . json_last_error_msg());
		}

		return $this->importFromDefinition($definition);
	}

	/**
	 * @return array{success:bool,results:array<int,string>,errors:array<int,string>,summary:array<string,int>}
	 */
	public function importDemoDefinition(): array
	{
		return $this->importFromFile(self::DEMO_JUMPSTART_FILE);
	}

	/**
	 * @param array<string, mixed> $definition
	 *
	 * @return array{success:bool,results:array<int,string>,errors:array<int,string>,summary:array<string,int>}
	 */
	public function importFromDefinition(array $definition): array
	{
		$this->results = [];
		$this->errors  = [];

		// Increase execution time for image generation
		set_time_limit(300); // 5 minutes

		$this->logger->info('Starting jumpstart import', [
			'name'    => $definition['name'] ?? 'Unknown',
			'version' => $definition['version'] ?? 'Unknown',
		]);

		// Need to process in this order to ensure dependencies are met
		if (isset($definition['schemas'])) {
			$this->processSchemas($definition['schemas']);
		}
		if (isset($definition['collections'])) {
			$this->processCollections($definition['collections']);
		}
		if (isset($definition['objects'])) {
			$this->processObjects($definition['objects']);
		}
		if (isset($definition['factory'])) {
			$this->processFactory($definition['factory']);
		}

		$result = [
			'success' => empty($this->errors),
			'results' => $this->results,
			'errors'  => $this->errors,
			'summary' => $this->generateSummary(),
		];

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $schemas
	 */
	private function processSchemas(array $schemas): void
	{
		foreach ($schemas as $schema) {
			try {
				$this->schemaSaver->saveSchema($schema);
				$this->addResult(sprintf('Schema %s: created', $schema['id'] ?? 'unknown'));
			} catch (\Exception $e) {
				$this->addError(sprintf('Schema %s: %s', $schema['id'] ?? 'unknown', $e->getMessage()));
			}
		}
	}

	/** @param array<string, mixed> $collections */
	private function processCollections(array $collections): void
	{
		// Process reserved collections
		if (isset($collections['reserved'])) {
			foreach ($collections['reserved'] as $collectionType) {
				try {
					$this->createReservedCollection($collectionType);
				} catch (\Exception $e) {
					$this->addError(sprintf('Collection %s: %s', $collectionType, $e->getMessage()));
				}
			}
		}

		// Process custom collections
		if (isset($collections['custom'])) {
			foreach ($collections['custom'] as $collectionDef) {
				try {
					$this->createCustomCollection($collectionDef);
				} catch (\Exception $e) {
					$this->addError(sprintf('Collection %s: %s', $collectionDef['id'] ?? 'unknown', $e->getMessage()));
				}
			}
		}
	}

	private function createReservedCollection(string $collectionType): void
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionType);
		if ($collection === null) {
			throw new \Exception("Error creating Reserved Collection: {$collectionType}");
		}
		$this->addResult(sprintf('Collection %s: created', $collection->id));
	}

	/** @param array<string, mixed> $collectionDef */
	private function createCustomCollection(array $collectionDef): void
	{
		$collection = $this->collectionSaver->saveCollection($collectionDef);
		$this->addResult(sprintf('Collection %s: created', $collection->id));
	}

	/** @param array<int,array<string,mixed>> $objects */
	private function processObjects(array $objects): void
	{
		foreach ($objects as $objectDef) {
			$collectionId = $objectDef['collection'] ?? '';
			$objectId     = $objectDef['id'] ?? '';
			$objectData   = $objectDef['data'] ?? [];
			try {
				$this->processObject($collectionId, $objectId, $objectData);
			} catch (\Exception $e) {
				$this->addError(sprintf('Object %s/%s: %s', $collectionId, $objectId, $e->getMessage()));
			}
		}
	}

	/** @param array<string,mixed> $objectData */
	private function processObject(string $collectionId, string $objectId, array $objectData): void
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionId);

		if ($collection === null) {
			throw new \Exception('Collection not found');
		}

		if ($this->objectFetcher->existsObject($collectionId, $objectId)) {
			$this->addResult(sprintf('Object %s/%s: already exists, skipping', $collectionId, $objectId));

			return;
		}

		// Generate object data using FactoryImporter and save
		$objectData = $this->generateFactoryObjectDataForImages($collectionId, $objectId, $objectData);
		$this->objectSaver->saveObject($collectionId, $objectData);
		$this->addResult(sprintf('Object %s/%s: created', $collectionId, $objectId));
	}

	/**
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,mixed>
	 */
	private function generateFactoryObjectDataForImages(string $collectionId, string $objectId, array $objectData): array
	{
		// Generate image-specific factory object data
		// Jumpstart does not support Image and Gallery import
		// They must be generated by the FactoryImporter
		return $this->generateFactoryObjectData($collectionId, $objectId, $objectData, fn (string $rule) => (
			str_starts_with($rule, 'image') || str_starts_with($rule, 'gallery')
		));
	}

	/**
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,mixed>
	 */
	private function generateFactoryObjectData(string $collectionId, string $objectId, array $objectData, ?callable $filterFakerRule = null): array
	{
		// Extract factory rules from the data
		$factoryRules = [];
		$staticData   = [];

		foreach ($objectData as $property => $value) {
			if (is_string($value) && $this->factoryImporter->isFakerRule($value)) {
				if ($filterFakerRule === null || $filterFakerRule($value)) {
					$factoryRules[$property] = $value;
					continue;
				}
			}
			$staticData[$property] = $value;
		}

		$factoryRules  = $this->factoryImporter->mergeFactoryDefinitions($collectionId, $factoryRules);
		$generatedData = $this->factoryImporter->generateFakeObject($collectionId, $factoryRules, $objectId);

		return array_merge($generatedData, $staticData);
	}

	/** @param array<int,array<string,mixed>> $factoryItems */
	private function processFactory(array $factoryItems): void
	{
		foreach ($factoryItems as $factoryDef) {
			$collectionId = $factoryDef['collection'];
			$factoryData  = $factoryDef['data'] ?? [];
			$factoryId    = $factoryDef['id'] ?? '';

			try {
				// Check if this is a specific ID factory item
				if (!empty($factoryId)) {
					$this->processSpecificFactoryObject($collectionId, $factoryId, $factoryData);
					continue;
				}
				// Regular bulk factory generation
				$count = $factoryDef['count'] ?? 1;
				$this->processBulkFactoryGeneration($collectionId, $count, $factoryData);
			} catch (\Exception $e) {
				$this->addError(sprintf('Factory %s/%s: %s', $collectionId, $factoryId, $e->getMessage()));
			}
		}
	}

	/** @param array<string,mixed> $factoryData */
	private function processSpecificFactoryObject(string $collectionId, string $objectId, array $factoryData): void
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionId);

		if ($collection === null) {
			throw new \Exception('Collection not found');
		}

		// Check if object already exists
		if ($this->objectFetcher->existsObject($collectionId, $objectId)) {
			$this->addResult(sprintf('Factory %s/%s: already exists, skipping', $collectionId, $objectId));

			return;
		}

		// Generate object data using the same pattern as processObjects
		$objectData = $this->generateFactoryObjectData($collectionId, $objectId, $factoryData);
		$this->objectSaver->saveObject($collectionId, $objectData);
		$this->addResult(sprintf('Factory %s/%s: generated', $collectionId, $objectId));
	}

	/** @param array<string, mixed> $factoryData */
	private function processBulkFactoryGeneration(string $collectionId, int $count, array $factoryData): void
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionId);

		if ($collection === null) {
			throw new \Exception('Collection not found');
		}

		// Use the same pattern as processObjects for merging factory definitions
		$finalFactoryDefs = $this->factoryImporter->mergeFactoryDefinitions($collectionId, $factoryData);

		// Import using FactoryImporter
		$imported = $this->factoryImporter->import($collectionId, $count, $finalFactoryDefs);

		$this->addResult(sprintf('Factory %s: generated %d items', $collectionId, $imported));
	}

	/** @return array<string,int> */
	private function generateSummary(): array
	{
		$summary = [
			'schemas_created'       => 0,
			'collections_created'   => 0,
			'objects_created'       => 0,
			'factory_items_created' => 0,
			'total_errors'          => count($this->errors),
		];

		foreach ($this->results as $result) {
			if (str_starts_with($result, 'Schema ')) {
				$summary['schemas_created']++;
			} elseif (str_starts_with($result, 'Collection ')) {
				$summary['collections_created']++;
			} elseif (str_starts_with($result, 'Object ')) {
				$summary['objects_created']++;
			} elseif (str_starts_with($result, 'Factory ')) {
				// Extract count from factory messages like "Factory blog: generated 5 items"
				if (preg_match('/generated (\d+) items/', $result, $matches)) {
					$summary['factory_items_created'] += (int)$matches[1];
					continue;
				}
				$summary['factory_items_created']++;
			}
		}

		return $summary;
	}
}
