<?php

namespace TotalCMS\Domain\Collection\Repository;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
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

    /**
     * The constructor.
     *
     * @param StorageFilesystemAdapter $filesystem The filesystem factory
     * @param CollectionFactory $CollectionFactory
     * @param CollectionFactory $factory
     */
    public function __construct(StorageAdapterInterface $filesystem, CollectionFactory $factory)
    {
        parent::__construct($filesystem);

        $this->factory = $factory;
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
     * Fetch a collection.
     *
     * @param string $collection
     *
     * @throws \DomainException
     *
     * @return CollectionData
     */
    public function getCollection(string $collection): CollectionData
    {
        $collection = $this->fetchCollection($collection);

        if ($collection === null) {
            throw new \DomainException(sprintf('Collection does not exist: %s', $collection));
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

        $jsonContent = $collection->toJson();
        $metaFile    = $this->buildMetaPath($collection->id);

        $this->filesystem->write($metaFile, $jsonContent);
    }

    /**
     * Create a collection for a reserved collection.
     *
     * @param string $collectionId The collection id
     *
     * @throws \DomainException
     *
     * @return CollectionData
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
