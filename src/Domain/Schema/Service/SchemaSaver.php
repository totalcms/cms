<?php

namespace App\Domain\Schema\Service;

use App\Domain\Collection\Service\CollectionReader;
use App\Domain\Schema\Data\SchemaData;
use App\Domain\Schema\Repository\SchemaRepository;
use App\Domain\Storage\CollectionStorage;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use UnexpectedValueException;

/**
 * Service.
 */
final class SchemaSaver
{
    private CollectionStorage $storage;

    private SchemaRepository $repository;

    private CollectionReader $collectionService;

    private Serializer $serializer;

    /**
     * Constructor.
     *
     * @param CollectionStorage $storage
     * @param SchemaRepository $repository The repository
     * @param CollectionReader $collectionService
     */
    public function __construct(
        CollectionStorage $storage,
        SchemaRepository $repository,
        CollectionReader $collectionService
    ) {
        $this->storage = $storage;
        $this->repository = $repository;
        $this->collectionService = $collectionService;
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * save a collection schema.
     *
     * @param string $collection
     * @param string $schemaJSON
     *
     * @throws RuntimeException
     * @throws UnexpectedValueException
     *
     * @return SchemaData
     */
    public function saveSchemaForCollection(string $collection, string $schemaJSON): SchemaData
    {
        // @todo What is this check good for?
        //$collection = $this->storage->fetchCollection($collection);

        //if ($collection->schema !== 'object') {
        //    throw new UnexpectedValueException("Not allowed to save non-object schemas ($collection->name)");
        //}

        $schema = $this->serializer->deserialize($schemaJSON, SchemaData::class, 'json');
        if (!$schema instanceof SchemaData) {
            throw new UnexpectedValueException('Invalid schema data provided', 1);
        }

        // TODO: Validate schema json against the schema.json schema to ensure proper formatting
        $this->repository->saveSchemaForCollection($collection, $schema);

        return $schema;
    }
}
