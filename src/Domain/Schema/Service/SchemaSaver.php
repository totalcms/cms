<?php

namespace App\Domain\Schema\Service;

use App\Domain\Schema\Data\SchemaData;
use App\Domain\Schema\Repository\SchemaRepository;
use RuntimeException;
use UnexpectedValueException;

/**
 * Service.
 */
final class SchemaSaver
{
    private SchemaRepository $storage;

    protected const ID_EXT = '.json#';

    public function __construct(SchemaRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Save a collection schema.
     *
     * @param string $schemaJSON
     *
     * @throws RuntimeException
     * @throws UnexpectedValueException
     *
     * @return SchemaData
     */
    public function saveSchema(string $schemaJSON): SchemaData
    {
        $data = json_decode($schemaJSON, true);

        // if name is provided, use the to create the ID
        // if (isset($data['type'])) {
        //     $data['$id'] = ".schemas/". $data['type'] . self::ID_EXT;
        // }

        $schema = new SchemaData();
        $schema->schema = $data;
        $schema->type = basename($schema->schema['$id'], self::ID_EXT);

        // TODO: Validate schema json against the schema.json schema to ensure proper formatting

        if (!$schema instanceof SchemaData) {
            throw new UnexpectedValueException('Invalid schema data provided', 1);
        }

        $this->storage->saveSchema($schema);
        return $schema;
    }
}
