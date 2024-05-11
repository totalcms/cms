<?php

namespace TotalCMS\Domain\Schema\Service;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Validator;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

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
     * @param array $object
     * @param string $schemaType
     *
     * @throws \DomainException
     *
     * @return bool
     */
    public function validateSchema(array $object, string $schemaType = 'schema'): bool
    {
        $schema     = $this->fetcher->fetchSchema($schemaType);
        $schemaJSON = $schema->toJson();

        $validator = new Validator();
        $resolver  = $validator->resolver();

        if ($resolver instanceof SchemaResolver) {
            $resolver->registerPrefix(SchemaData::SCHEMA_PREFIX, SchemaRepository::DEFAULT_SCHEMA_DIR);
        }

        $json = json_encode($object);
        if ($json === false) {
            throw new \DomainException('Failed to re-encode object: ' . json_last_error_msg());
        }
        $object = json_decode($json);

        $result = $validator->validate($object, $schemaJSON);
        $valid  = $result->isValid();

        if ($valid === false) {
            // Create an error formatter
            $formatter = new ErrorFormatter();
            /* @phpstan-ignore-next-line */
            $error = $formatter->format($result->error(), false);
            $msg   = implode(';', array_map(fn ($k, $v) => "($k) $v", array_keys($error), $error));
            throw new \DomainException("Schema Validation Failed. $msg");
        }

        return $valid;
    }
}
