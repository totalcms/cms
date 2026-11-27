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
		$schema     = $this->withInheritedProperties($this->schemaRepository->getSchema($schemaType));
		$schemaJSON = $this->schemaAsObject($schema);

		// Add custom validation for required string fields before JSON Schema validation
		$this->validateRequiredProperties($object, $schema);

		$validator = new Validator();
		$resolver  = $validator->resolver();

		if ($resolver instanceof SchemaResolver) {
			// Register default schemas and properties
			$resolver->registerPrefix(SchemaData::SCHEMA_PREFIX, SchemaRepository::defaultSchemaDir());
			$resolver->registerPrefix(SchemaData::SCHEMA_PREFIX . 'properties/', SchemaRepository::defaultSchemaDir() . 'properties/');
			// Register custom schemas with their own prefix
			$resolver->registerPrefix(SchemaData::SCHEMA_CUSTOM_PREFIX, $this->schemaRepository->getCustomSchemaDir());
		}

		// A schema made only of parts has no properties of its own. An empty
		// PHP array would be sent as a list, which is not an object.
		if ($schemaType === 'schema' && ($object['properties'] ?? null) === []) {
			$object['properties'] = new \stdClass();
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
	 * The schema as JSON-decoded data, with empty `properties` as an object.
	 * A schema none of whose parents could be read has no properties at all,
	 * and an empty PHP array would be sent as a list, which is not an object.
	 */
	private function schemaAsObject(SchemaData $schema): object
	{
		$decoded = json_decode($schema->toJson());
		if (!is_object($decoded)) {
			throw new \DomainException("Schema '{$schema->id}' cannot be encoded.");
		}
		if (($decoded->properties ?? null) === []) {
			$decoded->properties = new \stdClass();
		}

		return $decoded;
	}

	/**
	 * The schema with the properties and required names of every ancestor
	 * added, so an object is checked against all of its fields and not only the
	 * ones the schema itself declares. A schema that holds nothing of its own,
	 * such as one made only of parts, would otherwise validate anything. The
	 * schema's own definition wins over an ancestor's, and the first parent over
	 * the next, as when the schema is flattened for a form.
	 *
	 * @param list<string> $seen Ids already visited, so a loop ends
	 */
	private function withInheritedProperties(SchemaData $schema, int $depth = 1, array $seen = []): SchemaData
	{
		if ($schema->inheritFrom === []) {
			return $schema;
		}

		$resolved = clone $schema;
		$seen[]   = $schema->id;

		foreach ($schema->inheritFrom as $parentId) {
			if (in_array($parentId, $seen, true) || $depth > SchemaData::MAX_INHERIT_DEPTH) {
				continue;
			}

			try {
				$parent = $this->withInheritedProperties($this->schemaRepository->getSchema($parentId), $depth + 1, $seen);
			} catch (\Exception) {
				// Missing, unreadable or broken: skipped, with the same catch as
				// SchemaFetcher's walk, so a parent the form tolerates cannot fail a save.
				continue;
			}

			$resolved->properties = $resolved->properties + $parent->properties;
			$resolved->required   = array_values(array_unique(array_merge($resolved->required, $parent->required)));
		}

		return $resolved;
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

			// Check if boolean field is false (required booleans must be true)
			if ($this->isBooleanType($property) && $value !== true) {
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

	/**
	 * Check if a property is a boolean type.
	 *
	 * @param array<string,mixed> $property
	 */
	private function isBooleanType(array $property): bool
	{
		// Direct type check
		if (($property['type'] ?? null) === 'boolean') {
			return true;
		}

		// Check for boolean-based $ref
		if (!isset($property['$ref'])) {
			return false;
		}

		$booleanRefs = [
			'https://www.totalcms.co/schemas/properties/checkbox.json',
			'https://www.totalcms.co/schemas/properties/toggle.json',
		];

		return in_array($property['$ref'], $booleanRefs, true);
	}
}
