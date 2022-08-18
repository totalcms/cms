<?php

namespace App\Domain\Schema\Service;

use App\Domain\Schema\Repository\SchemaRepository;
use DomainException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Validator;

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
     * Validate a schema.
     *
     * @param string $schemaToValidate
     * @param string $schemaType
     *
     * @throws DomainException
     *
     * @return bool
     */
    public function validateSchema(string $schemaToValidate, string $schemaType = 'schema'): bool
    {
        $schema     = $this->fetcher->fetchSchema($schemaType);
        $schemaJSON = Helper::toJSON($schema->schema);

        $schemaToValidate = json_decode($schemaToValidate);

        $validator = new Validator();
        $resolver  = $validator->resolver();

        if ($resolver instanceof SchemaResolver) {
            $resolver->registerPrefix('https://www.totalcms.co/schemas/', SchemaRepository::DEFAULT_SCHEMA_DIR);
        }

        $result = $validator->validate($schemaToValidate, $schemaJSON);
        $valid  = $result->isValid();

        if ($valid === false) {
            // Create an error formatter
            $formatter = new ErrorFormatter();
            /* @phpstan-ignore-next-line */
            $error = $formatter->format($result->error(), false);
            $msg   = implode(';', array_map(fn ($k, $v) => "($k) $v", array_keys($error), $error));
            throw new DomainException("Schema Validation Failed. $msg");
        }

        return $valid;
    }
}
