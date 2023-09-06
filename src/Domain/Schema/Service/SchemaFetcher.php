<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Collection\Service\CollectionReader;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

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
        $this->storage           = $storage;
        $this->collectionService = $collectionService;
    }

    /**
     * fetch a schema.
     *
     * @param string $id
     *
     * @return SchemaData
     */
    public function fetchSchema(string $id): SchemaData
    {
        return $this->storage->getSchema($id);
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
