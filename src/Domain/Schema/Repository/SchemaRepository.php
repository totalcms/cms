<?php

namespace App\Domain\Schema\Repository;

use App\Domain\Filesystem\Repository\FilesystemRepository;
use App\Domain\Schema\Data\SchemaData;
use RuntimeException;

/**
 * Repository.
 */
final class SchemaRepository
{
    private FilesystemRepository $repository;

    /**
     * Constructor.
     *
     * @param FilesystemRepository $repository The filesystem factory
     */
    public function __construct(FilesystemRepository $repository)
    {
        $this->repository = $repository;
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
        $schema = $this->repository->fetchDefaultSchemaForType($type);
        if (null == $schema) {
            throw new RuntimeException("Default schema could not be located for $type", 1);
        }

        return $schema;
    }

    /**
     * fetch a schema for a custom object.
     *
     * @param string $collection
     *
     * @return SchemaData
     */
    public function fetchObjectSchemaForCollection(string $collection): SchemaData
    {
        $schema = $this->repository->fetchObjectSchemaForCollection($collection);
        if (null == $schema) {
            throw new RuntimeException("Object schema could not be located $collection", 1);
        }

        return $schema;
    }

    /**
     * save a schema.
     *
     * @param string $collection
     * @param SchemaData $schema
     */
    public function saveSchemaforCollection(string $collection, SchemaData $schema): bool
    {
        return $this->repository->saveSchemaForCollection($collection, $schema);
    }
}
