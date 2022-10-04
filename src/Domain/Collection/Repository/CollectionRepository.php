<?php

namespace App\Domain\Collection\Repository;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Storage\StorageRepository;
use App\Utils\PathUtils;
use DomainException;

/**
 * Repository.
 */
final class CollectionRepository extends StorageRepository
{
    private const META_FILE = '.meta.json';

    /**
     * List all Collections.
     *
     * @return array<CollectionData>
     */
    public function listAllCollections(): array
    {
        $collections = [];
        foreach ($this->filesystem->listDirectories('') as $name) {
            $collection = $this->fetchCollection($name);
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
     * @throws DomainException
     *
     * @return CollectionData
     */
    public function getCollection(string $collection): CollectionData
    {
        $collection = $this->fetchCollection($collection);

        if ($collection === null) {
            throw new DomainException(sprintf('Collection does not exist: %s', $collection));
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
        $jsonContent = $this->serializer->serialize($collection, 'json');
        $metaFile    = $this->buildMetaPath($collection->name);

        $this->filesystem->write($metaFile, $jsonContent);
    }

    private function buildMetaPath(string $collection): string
    {
        return PathUtils::buildPath(collection: $collection, filename: self::META_FILE);
    }
}
