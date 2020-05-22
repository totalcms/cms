<?php

namespace App\Domain\Collection\Service;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Collection\Repository\CollectionListRepository;
use App\Interfaces\ServiceInterface;

/**
 * Service.
 */
final class CollectionListService implements ServiceInterface
{
    private CollectionListRepository $repository;

    /**
     * Constructor.
     *
     * @param CollectionListRepository $repository The repository
     */
    public function __construct(CollectionListRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * List all collections
     *
     * @return array<object>
     */
    public function listAllCollections() : array
    {
        return $this->repository->listAllCollections();
    }
}
