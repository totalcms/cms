<?php

namespace App\Domain\Object\Repository;

use App\Domain\Object\Data\ObjectData;
use App\Repository\FilesystemRepository;
use App\Repository\RepositoryInterface;

/**
 * Repository.
 */
class ObjectRepository implements RepositoryInterface
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
     * save a object
     *
     * @param string     $collection
     * @param ObjectData $object
     */
    public function saveObject(string $collection, ObjectData $object) : bool
    {
        return $this->repository->saveObject($collection, $object);
    }
}
