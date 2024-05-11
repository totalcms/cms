<?php

namespace TotalCMS\Domain\Schema\Repository;

use Dynamics\Schema;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Storage\StorageRepository;

/**
 * Repository.
 */
final class SchemaRepository extends StorageRepository
{
    public const DEFAULT_SCHEMA_DIR = __DIR__ . '/../../../../schemas/';
    private const CUSTOM_SCHEMA_DIR = '.schemas/';

    private SchemaFactory $factory;

    /**
     * The constructor.
     *
     * @param StorageFilesystemAdapter $filesystem The filesystem factory
     * @param SchemaFactory $factory
     */
    public function __construct(StorageAdapterInterface $filesystem, SchemaFactory $factory)
    {
        parent::__construct($filesystem);
        $this->factory = $factory;
    }

    /**
     * List custom Schemas.
     *
     * @return array<SchemaData>
     */
    public function listCustomSchemas(): array
    {
        $files = $this->filesystem->listFiles(self::CUSTOM_SCHEMA_DIR);

        $schemas = [];

        foreach ($files as $file) {
            $id     = basename($file, self::FILE_EXT);
            $schema = $this->fetchCustomSchema($id);
            if ($schema !== null) {
                $schemas[] = $schema;
            }
        }

        return $schemas;
    }

    /**
     * List reserved Schemas.
     *
     * @return array<SchemaData>
     */
    public function listReservedSchemas(): array
    {
        $ids     = $this->reservedSchemasIds();
        $schemas = [];

        foreach ($ids as $id) {
            $schema = $this->fetchDefaultSchema($id);
            if ($schema !== null) {
                $schemas[] = $schema;
            }
        }

        return $schemas;
    }

    /**
     * List reserved Schema IDs.
     *
     * @return array<string>
     */
    public function reservedSchemasIds(): array
    {
        $files = glob(self::DEFAULT_SCHEMA_DIR . '*' . self::FILE_EXT);

        if ($files === false) {
            throw new \RuntimeException('Failed to list reserved schemas');
        }

        return array_map(function (string $file) {
            return basename($file, self::FILE_EXT);
        }, $files);
    }

    /**
     * fetch a schema for one of the default schema types.
     *
     * @param string $id
     *
     * @return ?SchemaData
     */
    public function fetchDefaultSchema(string $id): ?SchemaData
    {
        $schemaFile = self::DEFAULT_SCHEMA_DIR . $id . self::FILE_EXT;
        $contents   = null;

        // Cannot use flysystem here because
        // the file resides outside of the datadir
        if (file_exists($schemaFile)) {
            $contents = file_get_contents($schemaFile);
        }

        if (empty($contents)) {
            return null;
        }

        return $this->factory->generateSchemaFromJson($contents);
    }

    /**
     * fetch a schema for a custom schema type.
     *
     * @param string $id
     *
     * @return ?SchemaData
     */
    public function fetchCustomSchema(string $id): ?SchemaData
    {
        $schemaFile = self::CUSTOM_SCHEMA_DIR . $id . self::FILE_EXT;

        return $this->fetchAndDeserialize($schemaFile, SchemaData::class);
    }

    /**
     * fetch a schema for one of the default schema types.
     *
     * @param string $id
     *
     * @return SchemaData
     */
    public function getSchema(string $id): SchemaData
    {
        $schema = $this->fetchDefaultSchema($id);

        if ($schema === null) {
            $schema = $this->fetchCustomSchema($id);
        }

        if ($schema === null) {
            throw new \DomainException(sprintf('Schema type does not exist: %s', $id));
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
        $schemaFile = self::CUSTOM_SCHEMA_DIR . $schema->id . self::FILE_EXT;
        $schemaJSON = $schema->toJson();

        if (empty($schemaJSON)) {
            throw new \DomainException(sprintf('Failed to encode schema for type: %s', $schema->id));
        }

        $this->filesystem->write($schemaFile, $schemaJSON);
    }

    public function deleteSchema(string $id): bool
    {
        $schemaFile = self::CUSTOM_SCHEMA_DIR . $id . self::FILE_EXT;

        return $this->filesystem->delete($schemaFile);
    }
}
