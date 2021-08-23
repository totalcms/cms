<?php

namespace App\Domain\Collection\Repository;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Filesystem\Repository\FilesystemRepository;
use DomainException;

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
     * @throws DomainException
     *
     * @return CollectionData
     */
    public function fetchCollection(string $collection): CollectionData
    {
        $collection = $this->repository->fetchCollection($collection);

        if ($collection === null) {
            throw new DomainException('Collection does not exist');
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
