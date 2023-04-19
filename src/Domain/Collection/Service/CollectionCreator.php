<?php

namespace TotalCMS\Domain\Collection\Service;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;

/**
 * Service.
 */
final class CollectionCreator
{
    private CollectionRepository $storage;
    private Serializer $serializer;
    private IndexBuilder $indexBuilder;

    public function __construct(CollectionRepository $storage, IndexBuilder $indexBuilder)
    {
        $this->storage      = $storage;
        $this->indexBuilder = $indexBuilder;
        $this->serializer   = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * Save Collection data.
     *
     * @param string $data The collection data to save. This should be json encoded.
     *
     * @throws \UnexpectedValueException
     *
     * @return CollectionData
     */
    public function saveCollection(string $data): CollectionData
    {
        $collection = $this->serializer->deserialize($data, CollectionData::class, 'json');

        if (!$collection instanceof CollectionData) {
            throw new \UnexpectedValueException('Invalid Collection data provided');
        }

        if (
            in_array($collection->name, CollectionData::RESERVED_COLLECTIONS)
            && $collection->name !== $collection->schema
        ) {
            throw new \UnexpectedValueException('Cannot assign custom schema to a reserved collection');
        }

        $this->storage->saveCollection($collection);

        $this->indexBuilder->buildIndex($collection->name);

        return $collection;
    }

    /**
     * Save Collection data.
     *
     * @param string $collectionName The collection name
     *
     * @throws \DomainException
     *
     * @return CollectionData
     */
    public function saveReservedCollection(string $collectionName): CollectionData
    {
        if (!in_array($collectionName, CollectionData::RESERVED_COLLECTIONS)) {
            throw new \DomainException("Collection is not a reserved collection: $collectionName");
        }

        $collection         = new CollectionData();
        $collection->name   = $collectionName;
        $collection->schema = $collectionName;

        $this->storage->saveCollection($collection);

        $this->indexBuilder->buildIndex($collection->name);

        return $collection;
    }
}
