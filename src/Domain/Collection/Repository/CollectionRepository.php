<?php

namespace App\Domain\Collection\Repository;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Filesystem\Repository\FilesystemRepository;
use Exception;

/**
 * Repository.
 */
final class CollectionRepository
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
     * Fetch a collection.
     *
     * @param string $collection
     *
     * @return CollectionData
     */
    public function fetchCollection(string $collection): CollectionData
    {
        $collection = $this->repository->fetchCollection($collection);
        if (null == $collection) {
            throw new Exception('Collection does not exist', 1);
        }

        return $collection;
    }

    /**
     * Load data table entries.
     *
     * @return array<CollectionData>
     */
    public function listAllCollections(): array
    {
        return $this->repository->listAllCollections();
    }

    /**
     * Load data table entries.
     *
     * @param CollectionData $collection the collection to save
     *
     * @return void
     */
    public function saveCollection(CollectionData $collection): void
    {
        $this->repository->saveCollection($collection);
    }
}
