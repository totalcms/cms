<?php

namespace App\Domain\Schema\Service;

use App\Domain\Schema\Data\SchemaData;
use App\Domain\Schema\Service\SchemaFetcher;
use App\Domain\Schema\Service\SchemaFactory;
use App\Domain\Schema\Repository\SchemaRepository;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Helper;
use UnexpectedValueException;

/**
 * Service.
 */
final class SchemaSaver
{
    private SchemaRepository $storage;
    private SchemaFetcher $fetcher;

    protected const ID_EXT = '.json#';

    public function __construct(
        SchemaRepository $storage,
        SchemaFetcher $fetcher,
    ) {
        $this->storage = $storage;
        $this->fetcher = $fetcher;
    }

    /**
     * Validate a schema
     *
     * @param string $schemaToValidate
     *
     * @return bool
     */
    public function validateSchema(string $schemaToValidate): bool
    {
        $schema = $this->fetcher->fetchSchema('schema');
        $schemaJSON = Helper::toJSON($schema->schema);

        $schemaToValidate = json_decode($schemaToValidate);

        $validator = new Validator();
        $result = $validator->validate($schemaToValidate, $schemaJSON);

        return $result->isValid();
    }

    /**
     * Save a collection schema.
     *
     * @param string $schemaJSON
     *
     * @throws UnexpectedValueException
     *
     * @return SchemaData
     */
    public function saveSchema(string $schemaJSON): SchemaData
    {
        // TODO: check if default schema exists

        if ($this->validateSchema($schemaJSON) === false) {
            throw new UnexpectedValueException('Invalid schema data provided', 1);
        }

        $schema = SchemaFactory::generateSchema($schemaJSON);

        $this->storage->saveSchema($schema);
        return $schema;
    }
}
