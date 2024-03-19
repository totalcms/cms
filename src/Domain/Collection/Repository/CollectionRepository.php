<?php

namespace TotalCMS\Domain\Collection\Repository;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Schema\Service\SchemaValidator;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Utils\PathUtils;

/**
 * Repository.
 */
final class CollectionRepository extends StorageRepository
{
    private const META_FILE = '.meta.json';
    private CollectionFactory $factory;
    private SchemaValidator $validator;

    /**
     * The constructor.
     *
     * @param StorageFilesystemAdapter $filesystem The filesystem factory
     * @param CollectionFactory $factory
     * @param SchemaValidator $validator
     */
    public function __construct(StorageAdapterInterface $filesystem, CollectionFactory $factory, SchemaValidator $validator)
    {
        parent::__construct($filesystem);

        $this->factory   = $factory;
        $this->validator = $validator;
    }

    /**
     * List all Collections.
     *
     * @return array<CollectionData>
     */
    public function listAllCollections(): array
    {
        $collections = [];
        foreach ($this->filesystem->listDirectories('') as $id) {
            $collection = $this->fetchCollection($id);
            if ($collection == null) {
                continue;
            }
            $collections[] = $collection;
        }

        return $collections;
    }

    /**
     * Fetch a collection.
     *
     * @param string $collection
     *
     * @return CollectionData|null
     */
    public function fetchCollection(string $collection): ?CollectionData
    {
        $metaFile = $this->buildMetaPath($collection);

        return $this->fetchAndDeserialize($metaFile, CollectionData::class);
    }

    /**
     * Verify that a collection exists.
     *
     * @param string $collection
     */
    public function collectionExists(string $collection): bool
    {
        $metaFile = $this->buildMetaPath($collection);

        return $this->filesystem->fileExists($metaFile);
    }

    /**
     * Fetch a collection.
     *
     * @param string $collectionName
     *
     * @throws \DomainException
     *
     * @return CollectionData
     */
    public function getCollection(string $collectionName): CollectionData
    {
        $collection = $this->fetchCollection($collectionName);

        if ($collection === null) {
            throw new \DomainException(sprintf('Collection does not exist: %s', $collectionName));
        }
        if ($collection->isValid() === false) {
            throw new \DomainException(sprintf('Collection is invalid: %s', $collectionName));
        }

        return $collection;
    }

    /**
     * Save a Collection.
     *
     * @param CollectionData $collection The collection to save
     *
     * @return void
     */
    public function saveCollection(CollectionData $collection): void
    {
        if (in_array($collection->id, CollectionData::RESERVED_NAMES)) {
            throw new \UnexpectedValueException('Cannot save collection with a reserved name');
        }

        if ($this->validator->validateSchema($collection->toArray(), 'meta') === false) {
            throw new \UnexpectedValueException('Invalid Collection data provided. Failed schema validation.', 1);
        }

        $jsonContent = $collection->toJson();
        $metaFile    = $this->buildMetaPath($collection->id);

        $this->filesystem->write($metaFile, $jsonContent);
    }

    /**
     * Create a collection for a reserved collection.
     *
     * @param string $collectionId The collection id
     */
    public function saveReservedCollection(string $collectionId): void
    {
        $collection = $this->factory->generateReservedCollection($collectionId);

        $this->saveCollection($collection);
    }

    private function buildMetaPath(string $collection): string
    {
        return PathUtils::buildPath(collection: $collection, filename: self::META_FILE);
    }
}
