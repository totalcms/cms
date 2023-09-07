<?php

namespace TotalCMS\Domain\Collection\Service;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaValidator;

/**
 * Service.
 */
final class CollectionCreator
{
    private CollectionRepository $storage;
    private Serializer $serializer;
    private IndexBuilder $indexBuilder;
    private SchemaFetcher $schemaFetcher;
    private SchemaValidator $validator;

    public function __construct(
        SchemaFetcher $schemaFetcher,
        SchemaValidator $validator,
        CollectionRepository $storage,
        IndexBuilder $indexBuilder
    ) {
        $this->schemaFetcher   = $schemaFetcher;
        $this->storage         = $storage;
        $this->indexBuilder    = $indexBuilder;
        $this->serializer      = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * Generate Collection object.
     *
     * @param string $data The collection data to save. This should be json encoded.
     *
     * @throws \UnexpectedValueException
     *
     * @return CollectionData
     */
    public function generateCollection(string $data): CollectionData
    {
        $collection = $this->serializer->deserialize($data, CollectionData::class, 'json');

        if (!$collection instanceof CollectionData || !$collection->isValid()) {
            throw new \UnexpectedValueException('Invalid Collection data provided');
        }

        if (
            in_array($collection->id, CollectionData::RESERVED_COLLECTIONS)
            && $collection->id !== $collection->schema
        ) {
            throw new \UnexpectedValueException('Cannot assign custom schema to a reserved collection');
        }

        return $collection;
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
        $collection = $this->generateCollection($data);

        $schema = $this->schemaFetcher->fetchSchemaForCollection($collection->schema);

        if ($this->validator->validateSchema($collection->toJson(), $collection->schema) === false) {
            throw new \UnexpectedValueException('Invalid Collection data provided. Failed schema validation.', 1);
        }

        $this->storage->saveCollection($collection);

        $this->indexBuilder->buildIndex($collection->id);

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
        $collection->id     = $collectionName;
        $collection->schema = $collectionName;

        $this->storage->saveCollection($collection);

        $this->indexBuilder->buildIndex($collection->id);

        return $collection;
    }
}
