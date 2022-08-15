<?php

namespace App\Domain\Collection\Service;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Collection\Repository\CollectionRepository;

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
        return $this->storage->getCollection($collection);
    }
}
