<?php

namespace App\Domain\Schema\Service;

use App\Domain\Collection\Service\CollectionFetchService;
use App\Domain\Schema\Data\SchemaData;
use App\Domain\Schema\Repository\SchemaRepository;
use App\Interfaces\ServiceInterface;

/**
 * Service.
 */
final class SchemaFetchService implements ServiceInterface
{
    private SchemaRepository $repository;
    private CollectionFetchService $collectionService;

    /**
     * Constructor.
     *
     * @param SchemaRepository $repository The repository
     */
    public function __construct(SchemaRepository $repository, CollectionFetchService $collectionService)
    {
        $this->repository        = $repository;
        $this->collectionService = $collectionService;
    }

    /**
     * fetch a collection's schema
     *
     * @param string $collection
     *
     * @return SchemaData
     */
    public function fetchSchemaforCollection(string $collection): SchemaData
    {
        $collection = $this->collectionService->fetchCollection($collection);
        if ('object' == $collection->schema) {
            return $this->repository->fetchObjectSchemaForCollection($collection->name);
        }
        return $this->repository->fetchDefaultSchemaForType($collection->schema);
    }
}
