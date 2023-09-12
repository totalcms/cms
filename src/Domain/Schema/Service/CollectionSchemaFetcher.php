<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

/**
 * Service.
 */
final class CollectionSchemaFetcher
{
    private CollectionFetcher $collectionService;
    private SchemaRepository $storage;

    public function __construct(
        SchemaRepository $storage,
        CollectionFetcher $collectionService
    ) {
        $this->storage           = $storage;
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
        $collection = $this->collectionService->fetchCollection($collection);

        return $this->storage->getSchema($collection->schema);
    }
}
