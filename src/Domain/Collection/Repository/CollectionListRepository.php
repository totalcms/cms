<?php

namespace App\Domain\Collection\Repository;

use App\Repository\FilesystemRepository;
use App\Repository\RepositoryInterface;

/**
 * Repository.
 */
class CollectionListRepository implements RepositoryInterface
{
    private FilesystemRepository $repository;

    /**
     * Constructor.
     *
     * @param FilesystemRepository $repository The filesystem factory
     */
    public function __construct(FilesystemRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Load data table entries.
     *
     * @return array<array<mixed>>
     */
    public function listAllCollections() : array
    {
        return $this->repository->listAllCollections();
    }
}
