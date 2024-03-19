<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;

/**
 * Service.
 */
final class CollectionFetcher
{
    private CollectionRepository $storage;

    public function __construct(CollectionRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Fetch a collection.
     *
     * @param string $collectionId
     *
     * @return CollectionData
     */
    public function fetchCollection(string $collectionId): CollectionData
    {
        try {
            $collection = $this->storage->getCollection($collectionId);
        } catch (\DomainException $de) {
            // If the collection is not found or invalid, try to create it
            $this->storage->saveReservedCollection($collectionId);
            $collection = $this->storage->getCollection($collectionId);
        }

        return $collection;
    }
}
