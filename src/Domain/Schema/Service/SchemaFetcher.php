<?php

namespace App\Domain\Schema\Service;

use App\Domain\Collection\Service\CollectionReader;
use App\Domain\Schema\Data\SchemaData;
use App\Domain\Storage\CollectionStorage;

/**
 * Service.
 */
final class SchemaFetcher
{
    private CollectionReader $collectionService;
    private CollectionStorage $storage;

    public function __construct(
        CollectionStorage $storage,
        CollectionReader $collectionService
    ) {
        $this->storage = $storage;
        $this->collectionService = $collectionService;
    }

    /**
     * fetch a collection's schema.
     *
     * @param string $collection
     *
     * @return SchemaData
     */
    public function fetchSchemaForCollection(string $collection): SchemaData
    {
        $collection = $this->storage->getCollection($collection);
        if ($collection->schema === 'object') {
            return $this->storage->getObjectSchemaForCollection($collection->name);
        }

        return $this->storage->getDefaultSchemaForType($collection->schema);
    }
}
