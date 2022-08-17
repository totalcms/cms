<?php

namespace App\Domain\Collection\Service;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Collection\Repository\CollectionRepository;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use UnexpectedValueException;
use DomainException;

/**
 * Service.
 */
final class CollectionCreator
{
    private CollectionRepository $storage;
    private Serializer $serializer;

    public function __construct(CollectionRepository $storage)
    {
        $this->storage = $storage;
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * Save Collection data.
     *
     * @param string $data The collection data to save. This should be json encoded.
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

        if (in_array($collection->name, CollectionData::RESERVED_COLLECTIONS) && $collection->name !== $collection->schema) {
            throw new UnexpectedValueException('Cannot assign custom schema to a reserved collection');
        }

        $this->storage->saveCollection($collection);

        return $collection;
    }

    /**
     * Save Collection data.
     *
     * @param string $collectionName The collection name.
     *
     * @throws DomainException
     *
     * @return CollectionData
     */
    public function saveReservedCollection(string $collectionName): CollectionData
    {
        if (!in_array($collectionName, CollectionData::RESERVED_COLLECTIONS)) {
            throw new DomainException("Collection is not a reserved collection: $collectionName");
        }

        $collection = new CollectionData;
        $collection->name = $collectionName;
        $collection->schema = $collectionName;

        $this->storage->saveCollection($collection);

        return $collection;
    }
}
