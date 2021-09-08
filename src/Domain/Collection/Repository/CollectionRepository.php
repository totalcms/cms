<?php

namespace App\Domain\Collection\Repository;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Storage\CollectionStorage;
use DomainException;

/**
 * Repository.
 */
final class CollectionRepository
{
    private CollectionStorage $storage;

    /**
     * Constructor.
     *
     * @param CollectionStorage $storage The filesystem factory
     */
    public function __construct(CollectionStorage $storage)
    {
        $this->storage = $storage;
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
        $collection = $this->storage->fetchCollection($collection);

        if ($collection === null) {
            throw new DomainException('Collection does not exist');
        }

        return $collection;
    }

    /**
     * Load data table entries.
     *
     * @return array<CollectionData>
     */
    public function listAllCollections(): array
    {
        return $this->storage->listAllCollections();
    }

    /**
     * Load data table entries.
     *
     * @param CollectionData $collection the collection to save
     *
     * @return void
     */
    public function saveCollection(CollectionData $collection): void
    {
        $this->storage->saveCollection($collection);
    }
}
