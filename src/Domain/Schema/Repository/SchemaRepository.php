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
    private const DEFAULT_SCHEMA_DIR = __DIR__ . "/../../../../schemas/";
    private const CUSTOM_SCHEMA_DIR  = '.schemas/';

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
        $contents   = null;

        if (file_exists($schemaFile)) {
            $contents = file_get_contents($schemaFile);
        }

        if (empty($contents)) {
            return null;
        }

        $schema = new SchemaData();
        $schema->schema = json_decode($contents, true);
        $schema->type = $type;
        return $schema;
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
        $contents = null;

        if ($this->filesystem->fileExists($schemaFile)) {
            $contents = $this->filesystem->read($schemaFile);
        }

        if (empty($contents)) {
            return null;
        }

        $schema = new SchemaData();
        // Forcing an array so that fields like $id are easier to access
        $schema->schema = json_decode($contents, true);
        $schema->type = $type;
        return $schema;
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
     * @param SchemaData $schema
     *
     * @return void
     */
    public function saveSchema(SchemaData $schema): void
    {
        $schemaFile = self::CUSTOM_SCHEMA_DIR . $schema->type . self::FILE_EXT;
        $schemaJSON = json_encode($schema->schema);

        if (empty($schemaJSON)) {
            throw new DomainException(sprintf('Failed to encode schema for type: %s', $schema->type));
        }

        $this->filesystem->write($schemaFile, $schemaJSON);
    }
}
