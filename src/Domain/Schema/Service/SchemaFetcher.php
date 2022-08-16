<?php

namespace App\Domain\Schema\Service;

use App\Domain\Collection\Service\CollectionReader;
use App\Domain\Schema\Data\SchemaData;
use App\Domain\Schema\Repository\SchemaRepository;

/**
 * Service.
 */
final class SchemaFetcher
{
    private CollectionReader $collectionService;
    private SchemaRepository $storage;

    public function __construct(
        SchemaRepository $storage,
        CollectionReader $collectionService
    ) {
        $this->storage = $storage;
        $this->collectionService = $collectionService;
    }

    /**
     * fetch a schema.
     *
     * @param string $type
     *
     * @return SchemaData
     */
    public function fetchSchema(string $type): SchemaData
    {
        return $this->storage->getSchemaForType($type);
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
        $collection = $this->collectionService->fetchCollection($collection);
        return $this->fetchSchema($collection->schema);
    }
}
