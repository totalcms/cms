<?php

namespace TotalCMS\Domain\Import;

use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Utils\FakerExtension;

final class FactoryImporter
{
    private ObjectRepository $storage;
    private ObjectFactory $factory;
    private LoggerInterface $logger;
    private FakerGenerator $faker;
    private IndexBuilder $indexBuilder;
    private CollectionFetcher $collectionFetcher;
    private string $cacheDir;

    private const DEFAULT_FACTORY = 'word';

    public function __construct(ObjectFactory $factory, ObjectRepository $storage, LoggerFactory $loggerFactory, IndexBuilder $indexBuilder, Config $config, CollectionFetcher $collectionFetcher)
    {
        $this->indexBuilder      = $indexBuilder;
        $this->storage           = $storage;
        $this->factory           = $factory;
        $this->collectionFetcher = $collectionFetcher;
        $this->logger            = $loggerFactory->addFileHandler('importer-factory.log')->createLogger();

        $this->faker = FakerFactory::create();
        $this->faker->addProvider(new FakerExtension($this->faker));

        $this->cacheDir      = $config->cacheDir . '/faker-images';
        FakerExtension::$dir = $this->cacheDir;
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

    private static function parseFakerRule(string $rule): array
    {
        $parts  = explode('|', $rule);
        $method = $parts[0];
        $args   = [];
        if (count($parts) > 1) {
            $args = preg_split('/\s*,\s*/', $parts[1]);
            if ($args === false) {
                $args = [];
            }
        }

        return [$method, $args];
    }

    public function fetchCollectionFactories(string $collection): array
    {
        // Get factory definitions from collection
        $properties = $this->collectionFetcher->fetchCollection($collection)->properties;

        return array_map(function ($property) {
            return $property['factory'] ?? self::DEFAULT_FACTORY;
        }, $properties);
    }

    public function import(string $collection, int $quantity = 1, array $defs = []): int
    {
        $importCount = 0;

        // Get definitions from collection and merge with user provided definitions
        $defs = array_merge($this->fetchCollectionFactories($collection), $defs);

        for ($i = 0; $i < $quantity; $i++) {
            $objectData = [];
            foreach ($defs as $key => $value) {
                [$method, $args] = self::parseFakerRule($value ?? self::DEFAULT_FACTORY);
                if ($key === 'id') {
                    // Make sure ID is unique
                    $objectData[$key] = $this->faker->unique()->$method(...$args);
                }
                $objectData[$key] = $this->faker->$method(...$args);
            }

            if ($this->storage->existsObject($collection, $objectData['id'])) {
                $this->logger->info(sprintf('Skipping existing object: %s', $objectData['id']));
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
