<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Data\PropertyDefinition;

/**
 * Service to transform schemas with simplified deck syntax into full JSON Schema format.
 *
 * This allows users to write:
 * "features": {
 *     "field": "deck",
 *     "schemaref": "https://www.totalcms.co/schemas/custom/features.json",
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
 *
 * The legacy `deckref` key is still accepted as an alias for `schemaref`.
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

			$schemaRef = PropertyDefinition::extractSchemaRef($property);

			// Check if this property uses the simplified deck syntax
			if ($this->isDeckProperty($property) && $schemaRef !== null) {
				$transformedSchema['properties'][$propertyName] = $this->expandDeckProperty($property);
				continue;
			}

			// Check if this property uses the simplified card syntax. Cards are new,
			// but the shared `extractSchemaRef` helper accepts the legacy `deckref` alias
			// for free — no card-specific handling needed.
			if ($this->isCardProperty($property) && $schemaRef !== null) {
				$transformedSchema['properties'][$propertyName] = $this->expandCardProperty($property, $schemaRef);
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
			   && str_contains((string)$property['$ref'], '/properties/deck.json');
	}

	/**
	 * Check if a property is a card type property.
	 *
	 * @param array<string,mixed> $property
	 */
	private function isCardProperty(array $property): bool
	{
		return isset($property['$ref'])
			   && str_contains((string)$property['$ref'], '/properties/card.json');
	}

	/**
	 * Expand a simplified card property to point its `$ref` directly at the
	 * referenced sub-schema, so JSON Schema validates the nested object against
	 * the actual card shape rather than the generic card.json wrapper.
	 *
	 * Preserves the original schemaref/deckref keys for form building.
	 *
	 * @param array<string,mixed> $property
	 *
	 * @return array<string,mixed>
	 */
	private function expandCardProperty(array $property, string $schemaRef): array
	{
		$expanded = $property;

		// Validation against the card sub-schema directly
		$expanded['$ref'] = $schemaRef;

		return $expanded;
	}

	/**
	 * Expand a simplified deck property into full patternProperties format.
	 * Preserves the original schemaref/deckref for form building while adding
	 * patternProperties for validation.
	 *
	 * @param array<string,mixed> $property
	 *
	 * @return array<string,mixed>
	 */
	private function expandDeckProperty(array $property): array
	{
		$expanded      = $property;
		$deckSchemaRef = PropertyDefinition::extractSchemaRef($property);

		if ($deckSchemaRef !== null) {
			// Create the patternProperties structure
			$expanded['patternProperties'] = [
				'^[a-zA-Z]\\w*$' => [
					'$ref' => $deckSchemaRef,
				],
			];

			// Keep the schemaref/deckref property for form building - don't remove it
			// This allows both JSON Schema validation (via patternProperties)
			// and form generation (via the reference) to work
		}

		return $expanded;
	}
}
