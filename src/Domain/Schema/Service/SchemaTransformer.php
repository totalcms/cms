<?php

namespace TotalCMS\Domain\Schema\Service;

/**
 * Service to transform schemas with simplified deck syntax into full JSON Schema format.
 *
 * This allows users to write:
 * "features": {
 *     "field": "deck",
 *     "deckref": "https://www.totalcms.co/schemas/custom/features.json",
 *     "$ref": "https://www.totalcms.co/schemas/properties/deck.json"
 * }
 *
 * Instead of the more verbose:
 * "features": {
 *     "field": "deck",
 *     "patternProperties": {
 *         "^[a-zA-Z]\\w*$": {"$ref": "https://www.totalcms.co/schemas/custom/features.json"}
 *     },
 *     "$ref": "https://www.totalcms.co/schemas/properties/deck.json"
 * }
 */
class SchemaTransformer
{
	/**
	 * Transform a schema to expand simplified deck syntax into full JSON Schema format.
	 *
	 * @param array<string,mixed> $schema The schema to transform
	 *
	 * @return array<string,mixed> The transformed schema
	 */
	public function transformSchema(array $schema): array
	{
		if (!isset($schema['properties']) || !is_array($schema['properties'])) {
			return $schema;
		}

		$transformedSchema = $schema;

		foreach ($schema['properties'] as $propertyName => $property) {
			if (!is_array($property)) {
				continue;
			}

			// Check if this property uses the simplified deck syntax
			if ($this->isDeckProperty($property) && isset($property['deckref'])) {
				$transformedSchema['properties'][$propertyName] = $this->expandDeckProperty($property);
			}
		}

		return $transformedSchema;
	}

	/**
	 * Check if a property is a deck type property.
	 *
	 * @param array<string,mixed> $property
	 */
	private function isDeckProperty(array $property): bool
	{
		return isset($property['$ref'])
			   && str_contains((string) $property['$ref'], '/properties/deck.json');
	}

	/**
	 * Expand a simplified deck property into full patternProperties format.
	 * Preserves the original deckref for form building while adding patternProperties for validation.
	 *
	 * @param array<string,mixed> $property
	 *
	 * @return array<string,mixed>
	 */
	private function expandDeckProperty(array $property): array
	{
		$expanded = $property;

		// If deckref property exists, convert it to patternProperties
		if (isset($property['deckref'])) {
			$deckSchemaRef = $property['deckref'];

			// Create the patternProperties structure
			$expanded['patternProperties'] = [
				'^[a-zA-Z]\\w*$' => [
					'$ref' => $deckSchemaRef,
				],
			];

			// Keep the deckref property for form building - don't remove it
			// This allows both JSON Schema validation (via patternProperties)
			// and form generation (via deckref) to work
		}

		return $expanded;
	}
}
