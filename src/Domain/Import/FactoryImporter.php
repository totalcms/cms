<?php

namespace TotalCMS\Domain\Import;

use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Utils\FakerExtension;

final class FactoryImporter
{
    private ObjectRepository $storage;
    private LoggerInterface $logger;
    private FakerGenerator $faker;
    private IndexBuilder $indexBuilder;
    private CollectionFetcher $collectionFetcher;
    private string $cacheDir;

    public function __construct(ObjectRepository $storage, LoggerFactory $loggerFactory, IndexBuilder $indexBuilder, Config $config, CollectionFetcher $collectionFetcher)
    {
        $this->indexBuilder      = $indexBuilder;
        $this->storage           = $storage;
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

    public function import(string $collection, int $quantity = 1, array $defs = []): int
    {
        $importCount = 0;

        // Get definitions from collection if not provided
        if (empty($defs)) {
            $defs = $this->collectionFetcher->fetchCollection($collection)->factory;
        }

        for ($i = 0; $i < $quantity; $i++) {
            $object = [];
            foreach ($defs as $key => $value) {
                [$method, $args] = self::parseFakerRule($value);
                if ($key === 'id') {
                    // Make sure ID is unique
                    $object[$key] = $this->faker->unique()->$method(...$args);
                }
                $object[$key] = $this->faker->$method(...$args);
            }

            if ($this->storage->existsObject($collection, $object['id'])) {
                $this->logger->info(sprintf('Skipping existing object: %s', $object['id']));
                continue;
            }

            // Save the object
            $this->storage->saveObject($collection, new ObjectData($object['id'], $object));
            $this->logger->info(sprintf('Imported object: %s', $object['id']));
            $this->logger->debug('Imported object', $object);

            $importCount++;
        }

        // Rebuild index
        $this->indexBuilder->buildIndex($collection);

        // Clean cache
        $this->cleanCache();

        return $importCount;
    }
}
