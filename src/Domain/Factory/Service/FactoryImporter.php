<?php

namespace TotalCMS\Domain\Factory\Service;

use Faker\Generator as FakerGenerator;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\DataView\Service\DataViewFilter;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

class FactoryImporter
{
	public LoggerInterface $logger;
	public FakerGenerator $faker;
	public string $cacheDir;

	private const DEFAULT_FACTORY   = 'word';
	private const RELATIONAL_MARKER = '__relational__';

	/** @var array<string,array<string>> */
	private array $relationalCache = [];

	/** @var array<string,array<string,mixed>> */
	private array $relationalSettings = [];

	// ---------------------------------------------------------------------------------
	// This class uses the Repository classes directly instead of the Service classes
	// to avoid unnecessary complexity and performance overhead.
	// ---------------------------------------------------------------------------------

	public function __construct(
		private readonly ObjectFactory $objectFactory,
		private readonly ObjectRepository $objectRepository,
		private readonly IndexBuilder $indexBuilder,
		private readonly IndexReader $indexReader,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CollectionSaver $collectionSaver,
		private readonly SchemaFetcher $schemaFetcher,
		private readonly PropertyRepository $propertyRepository,
		private readonly DataViewFilter $dataViewFilter,
		FakerFactory $fakerFactory,
		LoggerFactory $loggerFactory,
	) {
		$this->logger   = $loggerFactory->addFileHandler('factory.log')->createLogger('factory');
		$this->faker    = $fakerFactory->createFaker();
		$this->cacheDir = $fakerFactory->cacheDir;
	}

	private function cleanCache(): void
	{
		$files = glob($this->cacheDir . '/*');
		if ($files === false) {
			return;
		}
		foreach ($files as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
	}

	public function isFakerRule(string $rule): bool
	{
		[$method, $args] = $this->parseFakerRule($rule);

		if (empty($method)) {
			return false;
		}

		return is_callable([$this->faker, $method]) && is_array($args);
	}

	/** @return array<mixed> */
	private function parseFakerRule(string $rule): array
	{
		// Extract method name and arguments string
		preg_match('/^(\w+)(\((.*)\))*$/', $rule, $matches);
		$method = $matches[1] ?? '';
		$args   = $matches[3] ?? '';
		$args   = trim($args);

		if ($args !== '' && $args !== '0') {
			$args = preg_split('/\s*,\s*/', trim($args));
			if ($args === false) {
				$args = [];
			}
		}

		if (empty($args)) {
			$args = [];
		}

		// Loop through $args and convert values to int or bool if applicable
		foreach ($args as &$arg) {
			if (filter_var($arg, FILTER_VALIDATE_INT) !== false) {
				$arg = (int)$arg; // Convert to int
			} elseif (filter_var($arg, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null) {
				$arg = filter_var($arg, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE); // Convert to bool
			}
			// Leave $arg as a string if it doesn't look like an int or a bool
		}
		unset($arg); // Break the reference with the last element

		return [$method, $args];
	}

	/**
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string>
	 */
	private function getRelationalIds(array $settings): array
	{
		$refCollection = $settings['collection'] ?? '';
		$refView       = $settings['view'] ?? '';
		$valueField    = $settings['value'] ?? 'id';

		if (!is_string($valueField)) {
			$valueField = 'id';
		}

		// Handle view-sourced relational data
		if (is_string($refView) && $refView !== '') {
			$cacheKey = "view:{$refView}:{$valueField}";

			if (isset($this->relationalCache[$cacheKey])) {
				return $this->relationalCache[$cacheKey];
			}

			$data = $this->dataViewFilter->getViewData($refView);
			$ids  = array_map(fn (array $item): string => (string)($item[$valueField] ?? ''), $data);
			$ids  = array_values(array_filter($ids));

			$this->relationalCache[$cacheKey] = $ids;

			return $this->relationalCache[$cacheKey];
		}

		if (!is_string($refCollection) || $refCollection === '') {
			return [];
		}

		$cacheKey = "{$refCollection}:{$valueField}";

		if (isset($this->relationalCache[$cacheKey])) {
			return $this->relationalCache[$cacheKey];
		}

		$index = $this->indexReader->fetchIndex($refCollection);
		$ids   = $index->objects->pluck($valueField)->filter()->values()->all();

		// Ensure all values are strings
		$this->relationalCache[$cacheKey] = array_map(strval(...), $ids);

		return $this->relationalCache[$cacheKey];
	}

	/** @return array<string,string> */
	public function fetchCollectionFactories(string $collection): array
	{
		// Get factory definitions from collection
		$collectionData = $this->collectionFetcher->fetchCollection($collection);

		if (is_null($collectionData)) {
			return [];
		}

		$schema = $this->schemaFetcher->fetchSchema($collectionData->schema);

		$factories = array_map(fn (array $property) => $property['factory'] ?? null, $schema->properties);

		// Filter out null values to only return properties that have factory definitions
		$factories = array_filter($factories);

		// Detect relationalOptions on properties that have no explicit factory rule
		foreach ($schema->properties as $propName => $propDef) {
			if (isset($factories[$propName])) {
				continue; // Explicit factory rule takes precedence
			}

			$settings = $propDef['settings'] ?? [];
			if (!is_array($settings)) {
				continue;
			}

			$relational = $settings['relationalOptions'] ?? null;
			if (is_array($relational) && (isset($relational['collection']) || isset($relational['view']))) {
				$factories[$propName]                = self::RELATIONAL_MARKER;
				$this->relationalSettings[$propName] = $relational;
			}
		}

		return $factories;
	}

	/**
	 * @param array<string,string> $defs
	 *
	 * @return array<string,mixed>
	 */
	public function generateFakeObject(string $collection, array $defs = [], string $id = ''): array
	{
		$objectData = ['id' => $id];

		if (empty($objectData['id'])) {
			// Generate object id first since other methods may require it
			[$method, $args]  = $this->parseFakerRule($defs['id'] ?? self::DEFAULT_FACTORY);
			$objectData['id'] = $this->faker->unique()->$method(...$args);
		}

		foreach ($defs as $property => $value) {
			if (empty($value) || $property === 'id') {
				continue;
			}

			// Handle relational properties
			if ($value === self::RELATIONAL_MARKER) {
				$settings = $this->relationalSettings[$property] ?? null;
				if (is_array($settings)) {
					$ids = $this->getRelationalIds($settings);
					if ($ids !== []) {
						$objectData[$property] = $this->faker->randomElement($ids);
					} else {
						$this->logger->warning('Referenced collection is empty for relational property, skipping', [
							'property'   => $property,
							'collection' => $settings['collection'] ?? 'unknown',
						]);
					}
				}
				continue;
			}

			[$method, $args] = $this->parseFakerRule($value);
			if (str_starts_with((string)$method, 'image')) {
				// Save image and store path in object data
				// Not using the ImageSaver here to avoid unnecessary complexity
				try {
					$path                  = $this->faker->$method(...$args);
					$objectData[$property] = $this->propertyRepository->saveImage($collection, $objectData['id'], $property, $path);
				} catch (\Exception $e) {
					$this->logger->warning('Failed to generate image for property, skipping', [
						'property'  => $property,
						'method'    => $method,
						'args'      => $args,
						'error'     => $e->getMessage(),
						'object_id' => $objectData['id'],
					]);
					// Skip this property but continue with the rest of the object
				}
				continue;
			}
			if (str_starts_with((string)$method, 'gallery')) {
				// Save images and store path in object data
				// Not using the GallerySaver here to avoid unnecessary complexity
				try {
					$paths                 = $this->faker->$method(...$args);
					$objectData[$property] = array_map(
						fn (string $path): array => $this->propertyRepository->saveImage($collection, $objectData['id'], $property, $path),
						$paths
					);
				} catch (\Exception $e) {
					$this->logger->warning('Failed to generate gallery images for property, skipping', [
						'property'  => $property,
						'method'    => $method,
						'args'      => $args,
						'error'     => $e->getMessage(),
						'object_id' => $objectData['id'],
					]);
					// Skip this property but continue with the rest of the object
				}
				continue;
			}
			try {
				$objectData[$property] = $this->faker->$method(...$args);
			} catch (\Exception $e) {
				$this->logger->warning('Failed to generate fake data for property, skipping', [
					'property'  => $property,
					'method'    => $method,
					'args'      => $args,
					'error'     => $e->getMessage(),
					'object_id' => $objectData['id'],
				]);
				// Skip this property but continue with the rest of the object
			}
		}

		return $objectData;
	}

	/**
	 * @param array<string,string> $defs
	 *
	 * @return array<string,string>
	 */
	public function mergeFactoryDefinitions(string $collection, array $defs): array
	{
		// Get definitions from collection and merge with user provided definitions
		return array_merge($this->fetchCollectionFactories($collection), $defs);
	}

	/** @param array<string,string> $defs */
	public function import(string $collection, int $quantity = 1, array $defs = []): int
	{
		$importCount = 0;

		// Get definitions from collection and merge with user provided definitions
		$defs = $this->mergeFactoryDefinitions($collection, $defs);

		for ($i = 0; $i < $quantity; $i++) {
			$objectData = $this->generateFakeObject($collection, $defs);

			if ($this->objectRepository->existsObject($collection, $objectData['id'])) {
				$this->logger->info(sprintf('Skipping existing object: %s', $objectData['id']));
				continue;
			}
			if (in_array($objectData['id'], ObjectData::RESERVED_NAMES)) {
				$this->logger->info(sprintf('Skipping object with reserved name: %s', $objectData['id']));
				continue;
			}

			// Save the object
			// The ObjectSaver class is not used here for performance.
			// ObjectSaver rebuilds the index after every save.
			// We do that once after all objects are saved.
			$object = $this->objectFactory->generateObject($collection, $objectData);
			$this->objectRepository->saveObject($collection, $object);
			$this->logger->info(sprintf('Imported object: %s', $objectData['id']));
			$this->logger->debug('Imported object', $objectData);

			$importCount++;
		}

		// Rebuild index
		$this->indexBuilder->buildIndex($collection);

		// Update collection counts for the imported objects
		if ($importCount > 0) {
			$this->collectionSaver->incrementCount($collection, $importCount);
			$this->collectionSaver->incrementTotalObjects($collection, $importCount);
		}

		// Clean cache
		$this->cleanCache();

		return $importCount;
	}
}
