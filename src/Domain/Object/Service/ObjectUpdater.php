<?php

namespace App\Domain\Object\Service;

use App\Domain\Object\Data\ObjectData;
use App\Domain\Object\Repository\ObjectRepository;
use RuntimeException;
use UnexpectedValueException;

/**
 * Service.
 */
final class ObjectUpdater
{
    private ObjectRepository $storage;
    private ObjectFactory $factory;

    public function __construct(ObjectRepository $storage, ObjectFactory $factory)
    {
        $this->storage = $storage;
        $this->factory = $factory;
    }

    /**
     * update a collection object.
     *
     * @param string $collection
     * @param string $id
     * @param string $newData
     *
     * @throws UnexpectedValueException
     * @throws RuntimeException
     *
     * @return ObjectData
     */
    public function updateObject(string $collection, string $id, string $newData): ObjectData
    {
        // Get the existing object
        $object = $this->storage->fetchObject($collection, $id);

        if (!$object instanceof ObjectData) {
            throw new UnexpectedValueException('Unable to locate object to update');
        }

        // Merge in new data
        $object->properties = $object->properties->merge(json_decode($newData, true));

        // Use the factory to revalidate the new object against the schema
        $object = $this->factory->generateObject($collection, $object->toJson());

        if (!$object instanceof ObjectData) {
            throw new UnexpectedValueException('Unable to merge data with object');
        }

        $this->storage->saveObject($collection, $object);

        return $object;
    }
}
