<?php

namespace App\Domain\Collection\Service;

use App\Domain\Collection\Repository\CollectionListRepository;
use App\Interfaces\ServiceInterface;

/**
 * Service.
 */
final class CollectionListData implements ServiceInterface
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
     * @return array<array<mixed>>
     */
    public function listAllCollections() : array
    {
        return $this->repository->listAllCollections();
    }
}
