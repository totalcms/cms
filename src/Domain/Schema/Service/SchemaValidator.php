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
readonly class SchemaValidator
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

		// Add custom validation for required string fields before JSON Schema validation
		$this->validateRequiredProperties($object, $schema);

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

	/**
	 * Validate that required fields are not empty.
	 *
	 * @param array<string,mixed> $object
	 *
	 * @throws \DomainException
	 */
	private function validateRequiredProperties(array $object, SchemaData $schema): void
	{
		$emptyFields = [];

		foreach ($schema->required as $fieldName) {
			$property = $schema->properties[$fieldName] ?? null;
			if ($property === null || !isset($object[$fieldName])) {
				continue;
			}

			// Skip if user has explicitly set minLength or minItems
			if (isset($property['minLength']) || isset($property['minItems'])) {
				continue;
			}

			$value = $object[$fieldName];

			// Check if string field is empty
			if ($this->isStringType($property) && $value === '') {
				$emptyFields[] = $property['label'] ?? $fieldName;
			}

			// Check if array field is empty
			if ($this->isArrayType($property) && is_array($value) && count($value) === 0) {
				$emptyFields[] = $property['label'] ?? $fieldName;
			}
		}

		if ($emptyFields !== []) {
			$fieldList = implode(', ', $emptyFields);
			throw new \DomainException("Required field(s) cannot be empty: {$fieldList}");
		}
	}

	/**
	 * Check if a property is a string type.
	 *
	 * @param array<string,mixed> $property
	 */
	private function isStringType(array $property): bool
	{
		// Direct type check
		if (($property['type'] ?? null) === 'string') {
			return true;
		}

		// Check for string-based $ref
		if (!isset($property['$ref'])) {
			return false;
		}

		$stringRefs = [
			'https://www.totalcms.co/schemas/properties/code.json',
			'https://www.totalcms.co/schemas/properties/date.json',
			'https://www.totalcms.co/schemas/properties/email.json',
			'https://www.totalcms.co/schemas/properties/password.json',
			'https://www.totalcms.co/schemas/properties/phone.json',
			'https://www.totalcms.co/schemas/properties/slug.json',
			'https://www.totalcms.co/schemas/properties/svg.json',
			'https://www.totalcms.co/schemas/properties/time.json',
			'https://www.totalcms.co/schemas/properties/url.json',
		];

		return in_array($property['$ref'], $stringRefs, true);
	}

	/**
	 * Check if a property is an array type.
	 *
	 * @param array<string,mixed> $property
	 */
	private function isArrayType(array $property): bool
	{
		// Direct type check
		if (($property['type'] ?? null) === 'array') {
			return true;
		}

		// Check for array-based $ref
		if (!isset($property['$ref'])) {
			return false;
		}

		$arrayRefs = [
			'https://www.totalcms.co/schemas/properties/list.json',
			'https://www.totalcms.co/schemas/properties/deck.json',
		];

		return in_array($property['$ref'], $arrayRefs, true);
	}

}
