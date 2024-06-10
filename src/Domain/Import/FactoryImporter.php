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
use TotalCMS\Factory\FakerFactory;
use TotalCMS\Factory\LoggerFactory;

final class FactoryImporter
{
    private LoggerInterface $logger;
    private FakerGenerator $faker;

    private const DEFAULT_FACTORY = 'word';

    public function __construct(
        private ObjectFactory $factory,
        private ObjectRepository $storage,
        private LoggerFactory $loggerFactory,
        private FakerFactory $fakerFactory,
        private IndexBuilder $indexBuilder,
        private CollectionFetcher $collectionFetcher,
        private PropertyRepository $propertyRepository
    ) {
        $this->logger = $this->loggerFactory->addFileHandler('importer-factory.log')->createLogger();
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

    private static function parseFakerRule(string $rule): array
    {
        // Extract method name and arguments string
        preg_match('/^(\w+)(\((.*)\))*$/', $rule, $matches);
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

    public function fetchCollectionFactories(string $collection): array
    {
        // Get factory definitions from collection
        $properties = $this->collectionFetcher->fetchCollection($collection);

        if (is_null($properties)) {
            return [];
        }

        return array_map(function ($property) {
            return $property['factory'] ?? null;
        }, $properties->properties);
    }

    public function import(string $collection, int $quantity = 1, array $defs = []): int
    {
        $importCount = 0;

        // Get definitions from collection and merge with user provided definitions
        $defs = array_merge($this->fetchCollectionFactories($collection), $defs);

        for ($i = 0; $i < $quantity; $i++) {
            $objectData = [];

            // Generate object id first since other methods may require it
            [$method, $args]  = self::parseFakerRule($defs['id'] ?? self::DEFAULT_FACTORY);
            $objectData['id'] = $this->faker->unique()->$method(...$args);

            foreach ($defs as $key => $value) {
                if (empty($value) || $key === 'id') {
                    continue;
                }
                [$method, $args] = self::parseFakerRule($value ?? self::DEFAULT_FACTORY);
                if (str_starts_with($method, 'image')) {
                    // Save image to file and store path in object data
                    $path             = $this->faker->$method(...$args);
                    $objectData[$key] = $this->propertyRepository->saveImage($collection, $objectData['id'], $key, $path);
                    continue;
                }
                $objectData[$key] = $this->faker->$method(...$args);
            }

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
            $object = $this->factory->generateObject($collection, $objectData);
            if (!$object instanceof ObjectData) {
                throw new \UnexpectedValueException('Invalid object data provided');
            }
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
