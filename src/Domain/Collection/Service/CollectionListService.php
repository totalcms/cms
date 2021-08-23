<?php

namespace App\Domain\Collection\Service;

use App\Domain\Collection\Repository\CollectionRepository;
use App\Interfaces\ServiceInterface;

/**
 * Service.
 */
final class CollectionListService implements ServiceInterface
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
