<?php

namespace TotalCMS\Domain\Import;

use Faker\Generator as FakerGenerator;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\FakerFactory;
use TotalCMS\Factory\LoggerFactory;

final class FactoryImporter
{
	private LoggerInterface $logger;
	private FakerGenerator $faker;

	private const DEFAULT_FACTORY  = 'word';
	private const FAKER_RULE_REGEX = '/^(\w+)(\((.*)\))*$/';

	public function __construct(
		private ObjectFactory $objectFactory,
		private ObjectRepository $storage,
		private LoggerFactory $loggerFactory,
		private FakerFactory $fakerFactory,
		private IndexBuilder $indexBuilder,
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
		private PropertyRepository $propertyRepository,
	) {
		$this->logger = $this->loggerFactory->addFileHandler('factory.log')->createLogger('factory');
		$this->faker  = $this->fakerFactory->createFaker();
	}

	private function cleanCache(): void
	{
		$files = glob($this->fakerFactory->cacheDir . '/*');
		if ($files === false) {
			return;
		}
		foreach ($files as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
	}

	public static function isFakerRule(string $rule): bool
	{
		// Check if the rule is a valid Faker method call
		$syntaxCheck = preg_match(self::FAKER_RULE_REGEX, $rule) === 1;
		if (!$syntaxCheck) {
			return false;
		}
		[$method, $args] = self::parseFakerRule($rule);
		return method_exists(FakerGenerator::class, $method) && is_array($args);
	}

	/** @return array<mixed> */
	private static function parseFakerRule(string $rule): array
	{
		// Extract method name and arguments string
		preg_match(self::FAKER_RULE_REGEX, $rule, $matches);
		$method = $matches[1] ?? '';
		$args   = $matches[3] ?? '';
		$args   = trim($args);

		if (!empty($args)) {
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

		return array_map(function ($property) {
			return $property['factory'] ?? null;
		}, $schema->properties);
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
			[$method, $args]  = self::parseFakerRule($defs['id'] ?? self::DEFAULT_FACTORY);
			$objectData['id'] = $this->faker->unique()->$method(...$args);
		}

		foreach ($defs as $key => $value) {
			if (empty($value) || $key === 'id') {
				continue;
			}
			[$method, $args] = self::parseFakerRule($value);
			if (str_starts_with($method, 'image')) {
				// Save image to file and store path in object data
				$path             = $this->faker->$method(...$args);
				$objectData[$key] = $this->propertyRepository->saveImage($collection, $objectData['id'], $key, $path);
				continue;
			}
			$objectData[$key] = $this->faker->$method(...$args);
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
		$defs = array_merge($this->fetchCollectionFactories($collection), $defs);

		return $defs;
	}

	/** @param array<string,string> $defs */
	public function import(string $collection, int $quantity = 1, array $defs = []): int
	{
		$importCount = 0;

		// Get definitions from collection and merge with user provided definitions
		$defs = $this->mergeFactoryDefinitions($collection, $defs);

		for ($i = 0; $i < $quantity; $i++) {
			$objectData = $this->generateFakeObject($collection, $defs);

			if ($this->storage->existsObject($collection, $objectData['id'])) {
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
			$this->storage->saveObject($collection, $object);
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
