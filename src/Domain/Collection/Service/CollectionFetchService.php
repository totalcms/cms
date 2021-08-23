<?php

namespace App\Domain\Collection\Service;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Collection\Repository\CollectionRepository;

/**
 * Service.
 */
final class CollectionFetchService
{
    private CollectionRepository $repository;

    /**
     * Constructor.
     *
     * @param CollectionRepository $repository The repository
     */
    public function __construct(CollectionRepository $repository)
    {
        $this->repository = $repository;
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
        return $this->repository->fetchCollection($collection);
    }
}
