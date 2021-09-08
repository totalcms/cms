<?php

namespace App\Domain\Object\Repository;

use App\Domain\Object\Data\ObjectData;
use App\Domain\Storage\CollectionStorage;

/**
 * Repository.
 */
final class ObjectRepository
{
    private CollectionStorage $storage;

    /**
     * Constructor.
     *
     * @param CollectionStorage $storage The filesystem factory
     */
    public function __construct(CollectionStorage $storage)
    {
        $this->storage = $storage;
    }

    /**
     * save a object.
     *
     * @param string $collection
     * @param ObjectData $object
     *
     * @return void
     */
    public function saveObject(string $collection, ObjectData $object): void
    {
        $this->storage->saveObject($collection, $object);
    }
}
