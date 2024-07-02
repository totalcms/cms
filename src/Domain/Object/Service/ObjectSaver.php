<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

/**
 * Service.
 */
final class ObjectSaver
{
    public function __construct(
        private ObjectRepository $storage,
        private PropertyRepository $propStorage,
        private ObjectFactory $factory,
        private IndexBuilder $indexBuilder
    ) {
        $this->storage      = $storage;
        $this->factory      = $factory;
        $this->indexBuilder = $indexBuilder;
        $this->propStorage  = $propStorage;
    }

    /**
     * save a collection object.
     *
     * @param string $collection
     * @param array<string,mixed> $objectData
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

        if ($this->storage->existsObject($collection, $object->id)) {
            throw new \DomainException(sprintf('Object with id %s already exists in %s', $object->id, $collection));
        }

        $this->storage->saveObject($collection, $object);

        $this->indexBuilder->buildIndex($collection);

        return $object;
    }

    /**
     * update a collection object.
     *
     * @param string $collection
     * @param string $id
     * @param array<string,mixed> $newData
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

    /** @param array<string,mixed> $newData */
    public function updateObjectProperty(string $collection, string $id, string $property, array $newData): ObjectData
    {
        $object = $this->storage->fetchObject($collection, $id);

        if (!$object instanceof ObjectData) {
            throw new \UnexpectedValueException('Unable to locate object to update');
        }

        $objectData            = $object->toArray();
        $objectData[$property] = $newData;

        return $this->updateObject($collection, $id, $objectData);
    }

    /** @param array<string,mixed> $newData */
    public function updateObjectPropertyMeta(string $collection, string $id, string $property, string $name, array $newData): ObjectData
    {
        $object = $this->storage->fetchObject($collection, $id);

        if (!$object instanceof ObjectData) {
            throw new \UnexpectedValueException('Unable to locate object to update');
        }

        $objectData   = $object->toArray();
        $propertyData = $objectData[$property];

        if (!is_array($propertyData)) {
            throw new \UnexpectedValueException('Property is not an array');
        }

        foreach ($propertyData as $index => $child) {
            if ($child['name'] === $name) {
                $propertyData[$index] = $newData;
                break;
            }
        }

        $objectData[$property] = $propertyData;

        return $this->updateObject($collection, $id, $objectData);
    }

    /**
     * patch a collection object.
     *
     * @param string $collection
     * @param string $id
     * @param array<string,mixed> $newData
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

    /** @param array<string,mixed> $newData */
    public function patchObjectProperty(string $collection, string $id, string $property, array $newData): ObjectData
    {
        $object = $this->storage->fetchObject($collection, $id);

        if (!$object instanceof ObjectData) {
            throw new \UnexpectedValueException('Unable to locate object to update');
        }

        $objectData            = $object->toArray();
        $objectData[$property] = array_merge($objectData[$property], $newData);

        return $this->updateObject($collection, $id, $objectData);
    }

    /** @param array<string,mixed> $newData */
    public function patchObjectPropertyMeta(string $collection, string $id, string $property, string $name, array $newData): ObjectData
    {
        $object = $this->storage->fetchObject($collection, $id);

        if (!$object instanceof ObjectData) {
            throw new \UnexpectedValueException('Unable to locate object to update');
        }

        $objectData   = $object->toArray();
        $propertyData = $objectData[$property];

        if (!is_array($propertyData)) {
            throw new \UnexpectedValueException('Property is not an array');
        }

        foreach ($propertyData as $index => $child) {
            if ($child['name'] === $name) {
                $propertyData[$index] = array_merge($child, $newData);
                break;
            }
        }

        $objectData[$property] = $propertyData;

        return $this->updateObject($collection, $id, $objectData);
    }

    public function deleteObjectProperty(string $collection, string $id, string $property): ObjectData
    {
        $object = $this->storage->fetchObject($collection, $id);

        if (!$object instanceof ObjectData) {
            throw new \UnexpectedValueException('Unable to locate object to update');
        }

        $objectData            = $object->toArray();
        $objectData[$property] = null;

        $this->propStorage->deleteDirectory($collection, $id, $property);

        return $this->updateObject($collection, $id, $objectData);
    }
}
