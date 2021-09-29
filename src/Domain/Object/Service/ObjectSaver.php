<?php

namespace App\Domain\Object\Service;

use App\Domain\Storage\CollectionStorage;
use App\Domain\Storage\ObjectData;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use UnexpectedValueException;

/**
 * Service.
 */
final class ObjectSaver
{
    private CollectionStorage $storage;
    private Serializer $serializer;

    public function __construct(CollectionStorage $storage)
    {
        $this->storage = $storage;
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
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
        $object = $this->serializer->deserialize($objectJSON, ObjectData::class, 'json');
        if (!$object instanceof ObjectData) {
            throw new UnexpectedValueException('Invalid object data provided');
        }

        $this->storage->saveObject($collection, $object);

        return $object;
    }
}
