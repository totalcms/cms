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
final readonly class SchemaValidator
{
	public function __construct(private SchemaRepository $schemaRepository)
	{
	}

	/**
	 * Validate a schema.
	 *
	 * @param array<string,mixed> $object
	 *
	 * @throws \DomainException
	 */
	public function validateSchema(array $object, string $schemaType = 'schema'): bool
	{
		$schema     = $this->schemaRepository->getSchema($schemaType);
		$schemaJSON = $schema->toJson();

		$validator = new Validator();
		$resolver  = $validator->resolver();

		if ($resolver instanceof SchemaResolver) {
			// Register default schemas and properties
			$resolver->registerPrefix(SchemaData::SCHEMA_PREFIX, SchemaRepository::DEFAULT_SCHEMA_DIR);
			$resolver->registerPrefix(SchemaData::SCHEMA_PREFIX . 'properties/', SchemaRepository::DEFAULT_SCHEMA_DIR . 'properties/');
			// Register custom schemas with their own prefix
			$resolver->registerPrefix(SchemaData::SCHEMA_CUSTOM_PREFIX, $this->schemaRepository->getCustomSchemaDir());
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
			$msg   = implode(';', array_map(fn ($k, $v): string => "($k) $v", array_keys($error), $error));
			throw new \DomainException("Schema Validation Failed. $msg");
		}

		return $valid;
	}
}
