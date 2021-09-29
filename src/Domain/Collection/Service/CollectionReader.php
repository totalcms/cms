<?php

namespace App\Domain\Collection\Service;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Storage\CollectionStorage;

/**
 * Service.
 */
final class CollectionReader
{
    private CollectionStorage $storage;

    public function __construct(CollectionStorage $storage)
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
