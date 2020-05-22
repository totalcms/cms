<?php

namespace App\Domain\Collection\Repository;

use App\Domain\Collection\Data\CollectionData;
use App\Repository\FilesystemRepository;
use App\Repository\RepositoryInterface;

/**
 * Repository.
 */
class CollectionSaveRepository implements RepositoryInterface
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
     * @param CollectionData $collection the collection to save
     *
     * @return bool
     */
    public function saveCollection(CollectionData $collection) : bool
    {
        return $this->repository->saveCollection($collection);
    }
}
