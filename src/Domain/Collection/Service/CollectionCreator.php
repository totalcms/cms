<?php

namespace App\Domain\Collection\Service;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Storage\CollectionStorage;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use UnexpectedValueException;

/**
 * Service.
 */
final class CollectionCreator
{
    private CollectionStorage $storage;
    private Serializer $serializer;

    public function __construct(CollectionStorage $storage)
    {
        $this->storage = $storage;
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * Save Collection data.
     *
     * @param string $data The collection data to save
     *
     * @throws UnexpectedValueException
     *
     * @return CollectionData
     */
    public function saveCollection(string $data): CollectionData
    {
        $collection = $this->serializer->deserialize($data, CollectionData::class, 'json');

        if (!$collection instanceof CollectionData) {
            throw new UnexpectedValueException('Invalid Collection data provided');
        }

        $this->storage->saveCollection($collection);

        return $collection;
    }
}
