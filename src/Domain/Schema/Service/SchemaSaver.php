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
     * @param array $schemaData
     *
     * @throws \UnexpectedValueException
     *
     * @return SchemaData
     */
    public function saveSchema(array $schemaData): SchemaData
    {
        $schema = $this->factory->generateSchema($schemaData);

        if (in_array($schema->id, SchemaData::RESERVED_SCHEMAS) || in_array($schema->id, $this->storage->reservedSchemasIds())) {
            throw new \UnexpectedValueException("Schema type ({$schema->id}) is reserved", 1);
        }

        // This is not in Repository in order to prevent circular dependency
        if ($this->validator->validateSchema($schema->toArray()) === false) {
            throw new \UnexpectedValueException('Invalid Schema data provided');
        }

        $this->storage->saveSchema($schema);

        return $schema;
    }
}
