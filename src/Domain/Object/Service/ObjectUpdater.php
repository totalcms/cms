<?php

namespace TotalCMS\Domain\Object\Service;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
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
    private Serializer $serializer;
    private IndexBuilder $indexBuilder;

    public function __construct(ObjectRepository $storage, ObjectFactory $factory, IndexBuilder $indexBuilder)
    {
        $this->storage      = $storage;
        $this->factory      = $factory;
        $this->indexBuilder = $indexBuilder;
        $this->serializer   = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * update a collection object.
     *
     * @param string $collection
     * @param string $id
     * @param string $newData
     *
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     *
     * @return ObjectData
     */
    public function updateObject(string $collection, string $id, string $newData): ObjectData
    {
        // Get the existing object
        $object = $this->storage->fetchObject($collection, $id);

        if (!$object instanceof ObjectData) {
            throw new \UnexpectedValueException('Unable to locate object to update');
        }

        $updatedProps = json_decode($newData, true);
        // $updatedProps = Items::fromString($newData, ['decoder' => new ExtJsonDecoder(true)]);

        if (!is_array($updatedProps)) {
            throw new \UnexpectedValueException('Unable to decode updated properties');
        }

        // Convert to array to merge updated properties.
        $objectArray = $object->toArray();
        $objectArray = array_merge($objectArray, $updatedProps);
        $objectJson  = $this->serializer->serialize($objectArray, 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);

        // Use the factory to revalidate the new object against the schema
        $object = $this->factory->generateObject($collection, $objectJson);

        if (!$object instanceof ObjectData) {
            throw new \UnexpectedValueException('Unable to merge data with object');
        }

        $this->storage->saveObject($collection, $object);

        $this->indexBuilder->buildIndex($collection);

        return $object;
    }
}
