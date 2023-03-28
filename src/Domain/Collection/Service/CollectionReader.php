<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use DomainException;

/**
 * Service.
 */
final class CollectionReader
{
    private CollectionRepository $storage;

    public function __construct(CollectionRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Fetch a collection.
     *
     * @param string $collection
     *
     * @return CollectionData
     */
    public function fetchCollection(string $collection): CollectionData
    {
        try {
            $collection = $this->storage->getCollection($collection);
        } catch (DomainException $de) {
            // Auto-generate reserved collections
            if (in_array($collection, CollectionData::RESERVED_COLLECTIONS)) {
                $reserved         = new CollectionData();
                $reserved->name   = $collection;
                $reserved->schema = $collection;
                $this->storage->saveCollection($reserved);

                return $reserved;
            }
            throw $de;
        }

        return $collection;
    }
}
