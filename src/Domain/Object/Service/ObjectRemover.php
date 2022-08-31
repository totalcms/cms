<?php

namespace App\Domain\Object\Service;

use App\Domain\Object\Repository\ObjectRepository;

/**
 * Service.
 */
final class ObjectRemover
{
    private ObjectRepository $storage;

    public function __construct(ObjectRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * delete a collection object.
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    public function deleteObject(string $collection, string $id): bool
    {
        // TODO: Need to rebuild the Collection Index
        return $this->storage->deleteObject($collection, $id);
    }
}
