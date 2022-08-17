<?php

namespace App\Domain\Schema\Service;

use App\Domain\Schema\Data\SchemaData;
use App\Domain\Schema\Service\SchemaFactory;
use App\Domain\Schema\Service\SchemaValidator;
use App\Domain\Schema\Repository\SchemaRepository;
use UnexpectedValueException;

/**
 * Service.
 */
final class SchemaSaver
{
    private SchemaRepository $storage;
    private SchemaValidator $validator;

    public function __construct(SchemaRepository $storage, SchemaValidator $validator)
    {
        $this->storage = $storage;
        $this->validator = $validator;
    }

    /**
     * Save a schema.
     *
     * @param string $schemaJSON
     *
     * @throws UnexpectedValueException
     *
     * @return SchemaData
     */
    public function saveSchema(string $schemaJSON): SchemaData
    {
        if ($this->validator->validateSchema($schemaJSON) === false) {
            throw new UnexpectedValueException('Invalid schema data provided', 1);
        }

        $schema = SchemaFactory::generateSchema($schemaJSON);

        if (in_array($schema->type, SchemaData::RESERVED_SCHEMAS)) {
            throw new UnexpectedValueException("Schema type ({$schema->type}) is reserved", 1);
        }

        $this->storage->saveSchema($schema);
        return $schema;
    }
}
