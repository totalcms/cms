<?php

namespace App\Domain\Schema\Service;

use App\Domain\Collection\Service\CollectionReader;
use App\Domain\Schema\Data\SchemaData;
use App\Domain\Schema\Repository\SchemaRepository;

/**
 * Service.
 */
final class SchemaFetchService
{
    private SchemaRepository $repository;
    private CollectionReader $collectionService;

    /**
     * Constructor.
     *
     * @param SchemaRepository $repository The repository
     * @param CollectionReader $collectionService
     */
    public function __construct(SchemaRepository $repository, CollectionReader $collectionService)
    {
        $this->repository = $repository;
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
        if ($collection->schema === 'object') {
            return $this->repository->fetchObjectSchemaForCollection($collection->name);
        }

        return $this->repository->fetchDefaultSchemaForType($collection->schema);
    }
}
