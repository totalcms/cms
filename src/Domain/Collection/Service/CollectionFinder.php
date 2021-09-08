<?php

namespace App\Domain\Collection\Service;

use App\Domain\Collection\Repository\CollectionRepository;

/**
 * Service.
 */
final class CollectionFinder
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
     * List all collections.
     *
     * @return array<object>
     */
    public function listAllCollections(): array
    {
        return $this->repository->listAllCollections();
    }
}
