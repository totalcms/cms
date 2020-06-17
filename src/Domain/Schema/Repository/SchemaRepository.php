<?php

namespace App\Domain\Schema\Repository;

use App\Repository\FilesystemRepository;
use App\Repository\RepositoryInterface;

/**
 * Repository.
 */
class SchemaRepository implements RepositoryInterface
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
     * @return array<object>
     */
    public function listAllCollections() : array
    {
        return $this->repository->listAllCollections();
    }
}
