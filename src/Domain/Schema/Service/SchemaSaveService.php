<?php

namespace App\Domain\Schema\Service;

use App\Domain\Collection\Service\CollectionFetchService;
use App\Domain\Schema\Data\SchemaData;
use App\Domain\Schema\Repository\SchemaRepository;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use UnexpectedValueException;

/**
 * Service.
 */
final class SchemaSaveService
{
    private SchemaRepository $repository;
    private CollectionFetchService $collectionService;
    private Serializer $serializer;

    /**
     * Constructor.
     *
     * @param SchemaRepository $repository The repository
     * @param CollectionFetchService $collectionService
     */
    public function __construct(SchemaRepository $repository, CollectionFetchService $collectionService)
    {
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
        $collection = $this->collectionService->fetchCollection($collection);
        if ('object' != $collection->schema) {
            throw new UnexpectedValueException("Not allowed to save non-object schemas ($collection->name)", 1);
        }

        $schema = $this->serializer->deserialize($schemaJSON, SchemaData::class, 'json');
        if (!$schema instanceof SchemaData) {
            throw new UnexpectedValueException('Invalid schema data provided', 1);
        }

        // TODO: Validate schema json against the schema.json schema to ensure proper formatting
        $this->repository->saveSchemaForCollection($collection->name, $schema);

        return $schema;
    }
}
