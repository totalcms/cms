<?php

namespace App\Domain\Object\Service;

use App\Domain\Object\Data\ObjectData;
use App\Domain\Object\Repository\ObjectRepository;

/**
 * Service.
 */
final class ObjectFetcher
{
    private ObjectRepository $storage;

    public function __construct(ObjectRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * get a collection object.
     *
     * @param string $collection
     * @param string $id
     *
     * @return ObjectData
     */
    public function fetchObject(string $collection, string $id): ObjectData
    {
        return $this->storage->fetchObject($collection, $id);
    }

    /**
     * get a collection object.
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    public function existsObject(string $collection, string $id): bool
    {
        return $this->storage->existsObject($collection, $id);
    }
}
