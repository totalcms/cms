<?php

namespace App\Domain\Schema\Repository;

use App\Domain\Schema\Data\SchemaData;
use App\Domain\Storage\CollectionStorage;
use RuntimeException;

/**
 * Repository.
 */
final class SchemaSaverRepository
{
    private CollectionStorage $storage;

    /**
     * Constructor.
     *
     * @param CollectionStorage $storage The filesystem factory
     */
    public function __construct(CollectionStorage $storage)
    {
        $this->storage = $storage;
    }

    /**
     * fetch a schema for one of the default schema types.
     *
     * @param string $type
     *
     * @return SchemaData
     */
    public function fetchDefaultSchemaForType(string $type): SchemaData
    {
        $schema = $this->storage->fetchDefaultSchemaForType($type);
        if (null == $schema) {
            throw new RuntimeException("Default schema could not be located for $type");
        }

        return $schema;
    }

    /**
     * Fetch a schema for a custom object.
     *
     * @param string $collection
     *
     * @return SchemaData
     */
    public function fetchObjectSchemaForCollection(string $collection): SchemaData
    {
        $schema = $this->storage->fetchObjectSchemaForCollection($collection);

        if (null == $schema) {
            throw new RuntimeException("Object schema could not be located $collection");
        }

        return $schema;
    }

    /**
     * save a schema.
     *
     * @param string $collection
     * @param SchemaData $schema
     *
     * @return void
     */
    public function saveSchemaForCollection(string $collection, SchemaData $schema): void
    {
        $this->storage->saveSchemaForCollection($collection, $schema);
    }
}
