<?php

namespace App\Domain\Schema\Repository;

use App\Domain\Storage\StorageRepository;
use App\Domain\Schema\Data\SchemaData;
use DomainException;

/**
 * Repository.
 */
final class SchemaRepository extends StorageRepository
{
    private const DEFAULT_SCHEMA_DIR = __DIR__ . "/../../../schemas/";
    private const CUSTOM_SCHEMA_DIR  = '.schemas';

    /**
     * fetch a schema for one of the default schema types.
     *
     * @param string $type
     *
     * @return ?SchemaData
     */
    public function fetchDefaultSchemaForType(string $type): ?SchemaData
    {
        $schemaFile = self::DEFAULT_SCHEMA_DIR . $type . self::FILE_EXT;
        return $this->fetchAndDeserialize($schemaFile, SchemaData::class);
    }

    /**
     * fetch a schema for a custom schema type
     *
     * @param string $type
     *
     * @return ?SchemaData
     */
    public function fetchCustomSchemaForType(string $type): ?SchemaData
    {
        $schemaFile = self::CUSTOM_SCHEMA_DIR . $type . self::FILE_EXT;
        return $this->fetchAndDeserialize($schemaFile, SchemaData::class);
    }

    /**
     * fetch a schema for one of the default schema types.
     *
     * @param string $type
     *
     * @return SchemaData
     */
    public function getSchemaForType(string $type): SchemaData
    {
        $schema = $this->fetchDefaultSchemaForType($type);

        if ($schema === null) {
            $schema = $this->fetchCustomSchemaForType($type);
        }

        if ($schema === null) {
            throw new DomainException(sprintf('Type does not exist: %s', $type));
        }

        return $schema;
    }

    /**
     * save a collection schema.
     *
     * @param string $type
     * @param SchemaData $schema
     *
     * @return void
     */
    public function saveSchemaForType(string $type, SchemaData $schema): void
    {
        $schemaFile = self::CUSTOM_SCHEMA_DIR . $type . self::FILE_EXT;
        $schemaJSON = $this->serializer->serialize($schema, 'json');

        $this->filesystem->write($schemaFile, $schemaJSON);
    }
}
