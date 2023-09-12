<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

/**
 * Service.
 */
final class ObjectSaver
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
     * save a collection object.
     *
     * @param string $collection
     * @param array $objectData
     *
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     *
     * @return ObjectData
     */
    public function saveObject(string $collection, array $objectData): ObjectData
    {
        $object = $this->factory->generateObject($collection, $objectData);

        if (!$object instanceof ObjectData) {
            throw new \UnexpectedValueException('Invalid object data provided');
        }

        $this->storage->saveObject($collection, $object);

        $this->indexBuilder->buildIndex($collection);

        return $object;
    }
}
