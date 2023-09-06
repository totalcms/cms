<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

/**
 * Service.
 */
final class SchemaSaver
{
    private SchemaValidator $validator;
    private SchemaRepository $storage;
    private SchemaFactory $factory;

    public function __construct(SchemaRepository $storage, SchemaFactory $factory, SchemaValidator $validator)
    {
        $this->storage   = $storage;
        $this->factory   = $factory;
        $this->validator = $validator;
    }

    /**
     * Save a schema.
     *
     * @param string $schemaJSON
     *
     * @throws \UnexpectedValueException
     *
     * @return SchemaData
     */
    public function saveSchema(string $schemaJSON): SchemaData
    {
        $schema = $this->factory->generateSchema($schemaJSON);

        if (in_array($schema->id, SchemaData::RESERVED_SCHEMAS)) {
            throw new \UnexpectedValueException("Schema type ({$schema->id}) is reserved", 1);
        }

        if ($this->validator->validateSchema($schema->toJson()) === false) {
            throw new \UnexpectedValueException('Invalid Schema data provided');
        }

        $this->storage->saveSchema($schema);

        return $schema;
    }
}
