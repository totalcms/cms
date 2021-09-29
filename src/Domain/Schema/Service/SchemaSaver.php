<?php

namespace App\Domain\Schema\Service;

use App\Domain\Collection\Service\CollectionReader;
use App\Domain\Schema\Data\SchemaData;
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

    private CollectionReader $collectionService;

    private Serializer $serializer;

    public function __construct(
        CollectionStorage $storage,
        CollectionReader $collectionService
    ) {
        $this->storage = $storage;
        $this->collectionService = $collectionService;
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * Save a collection schema.
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
        $schema = $this->serializer->deserialize($schemaJSON, SchemaData::class, 'json');
        if (!$schema instanceof SchemaData) {
            throw new UnexpectedValueException('Invalid schema data provided', 1);
        }

        // TODO: Validate schema json against the schema.json schema to ensure proper formatting
        $this->storage->saveSchemaForCollection($collection, $schema);

        return $schema;
    }
}
