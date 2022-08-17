<?php

namespace App\Domain\Object\Service;

use App\Domain\Object\Repository\ObjectRepository;
use App\Domain\Object\Data\ObjectData;
use App\Domain\Object\Service\ObjectFactory;
use UnexpectedValueException;
use RuntimeException;

/**
 * Service.
 */
final class ObjectSaver
{
    private ObjectRepository $storage;
    private ObjectFactory $factory;

    public function __construct(ObjectRepository $storage, ObjectFactory $factory)
    {
        $this->storage = $storage;
        $this->factory = $factory;
    }

    /**
     * save a collection object.
     *
     * @param string $collection
     * @param string $objectJSON
     *
     * @throws UnexpectedValueException
     * @throws RuntimeException
     *
     * @return ObjectData
     */
    public function saveObject(string $collection, string $objectJSON): ObjectData
    {
        $object = $this->factory->generateObject($collection, $objectJSON);

        if (!$object instanceof ObjectData) {
            throw new UnexpectedValueException('Invalid object data provided');
        }

        $this->storage->saveObject($collection, $object);

        return $object;
    }
}
