<?php

namespace App\Domain\Schema\Service;

use App\Domain\Schema\Service\SchemaFetcher;
use App\Domain\Schema\Repository\SchemaRepository;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Helper;

/**
 * Service.
 */
final class SchemaValidator
{
    private SchemaFetcher $fetcher;

    public function __construct(SchemaFetcher $fetcher)
    {
        $this->fetcher = $fetcher;
    }

    /**
     * Validate a schema
     *
     * @param string $schemaToValidate
     * @param string $schemaType
     *
     * @return bool
     */
    public function validateSchema(string $schemaToValidate, string $schemaType = 'schema'): bool
    {
        $schema = $this->fetcher->fetchSchema($schemaType);
        $schemaJSON = Helper::toJSON($schema->schema);

        $schemaToValidate = json_decode($schemaToValidate);

        $validator = new Validator();
        $result = $validator->validate($schemaToValidate, $schemaJSON);

        return $result->isValid();
    }
}
