<?php

namespace TotalCMS\Domain\Factory\Service;

use Faker\Generator as FakerGenerator;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

readonly class FactoryImporter
{
	public LoggerInterface $logger;
	public FakerGenerator $faker;
	public string $cacheDir;

	private const DEFAULT_FACTORY  = 'word';

	// ---------------------------------------------------------------------------------
	// This class uses the Repository classes directly instead of the Service classes
	// to avoid unnecessary complexity and performance overhead.
	// ---------------------------------------------------------------------------------

	public function __construct(
		private ObjectFactory $objectFactory,
		private ObjectRepository $objectRepository,
		private IndexBuilder $indexBuilder,
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
		private PropertyRepository $propertyRepository,
		private CacheManager $cacheManager,
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
		$this->cacheManager->clearAllCaches();
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

	/** @return array<string,string> */
	public function fetchCollectionFactories(string $collection): array
	{
		// Get factory definitions from collection
		$collection = $this->collectionFetcher->fetchCollection($collection);

		if (is_null($collection)) {
			return [];
		}

		$schema = $this->schemaFetcher->fetchSchema($collection->schema);

		$factories = array_map(fn (array $property) => $property['factory'] ?? null, $schema->properties);

		// Filter out null values to only return properties that have factory definitions
		return array_filter($factories);
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

		// Clean cache
		$this->cleanCache();

		return $importCount;
	}
}
