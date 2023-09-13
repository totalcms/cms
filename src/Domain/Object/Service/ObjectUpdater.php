<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

/**
 * Service.
 */
final class ObjectUpdater
{
    private ObjectRepository $storage;
    private ObjectFactory $factory;
    private IndexBuilder $indexBuilder;

    public function __construct(ObjectRepository $storage, ObjectFactory $factory, IndexBuilder $indexBuilder)
    {
        $this->storage      = $storage;
        $this->factory      = $factory;
        $this->indexBuilder = $indexBuilder;
    }

    /**
     * update a collection object.
     *
     * @param string $collection
     * @param string $id
     * @param array $newData
     *
     * @throws \UnexpectedValueException
     *
     * @return ObjectData
     */
    public function updateObject(string $collection, string $id, array $newData): ObjectData
    {
        $object = $this->factory->generateObject($collection, $newData);

        if (!$object instanceof ObjectData) {
            throw new \UnexpectedValueException('Unable to locate object to update');
        }
        if ($object->id !== $id) {
            throw new \UnexpectedValueException('Invalid Object data provided. Does not match object ID.', 1);
        }

        $this->storage->saveObject($collection, $object);
        $this->indexBuilder->buildIndex($collection);

        return $object;
    }

    /**
     * patch a collection object.
     *
     * @param string $collection
     * @param string $id
     * @param array $newData
     *
     * @throws \UnexpectedValueException
     *
     * @return ObjectData
     */
    public function patchObject(string $collection, string $id, array $newData): ObjectData
    {
        $object = $this->storage->fetchObject($collection, $id);

        if (!$object instanceof ObjectData) {
            throw new \UnexpectedValueException('Unable to locate object to update');
        }

        $mergedObject = array_merge($object->toArray(), $newData);

        return $this->updateObject($collection, $id, $mergedObject);
    }
}
