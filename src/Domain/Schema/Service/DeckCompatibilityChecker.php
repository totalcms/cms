<?php

namespace TotalCMS\Domain\Schema\Service;

/**
 * Service to check if a schema is compatible with deck usage.
 *
 * Deck-compatible schemas must only contain properties that don't require file uploads
 * or complex data structures that aren't supported in the deck format.
 */
final class DeckCompatibilityChecker
{
	public function __construct(
		private ?SchemaFetcher $schemaFetcher = null,
	) {
	}
	/**
	 * Property types that are NOT compatible with deck usage.
	 * These types involve file handling or complex structures.
	 */
	private const INCOMPATIBLE_TYPES = [
		'image',
		'gallery',
		'file',
		'depot',
	];

	/**
	 * Property references that are NOT compatible with deck usage.
	 */
	private const INCOMPATIBLE_REFS = [
		'https://www.totalcms.co/schemas/properties/image.json',
		'https://www.totalcms.co/schemas/properties/gallery.json',
		'https://www.totalcms.co/schemas/properties/file.json',
		'https://www.totalcms.co/schemas/properties/depot.json',
	];

	/**
	 * Check if a schema is compatible with deck usage.
	 *
	 * @param array<string,mixed> $schema The schema array to check
	 *
	 * @return bool True if compatible, false otherwise
	 */
	public function isCompatible(array $schema): bool
	{
		// Check if schema has properties
		if (!isset($schema['properties']) || !is_array($schema['properties'])) {
			return true; // Empty schema is compatible
		}

		// Check each property for compatibility
		foreach ($schema['properties'] as $propertyName => $property) {
			if (!$this->isPropertyCompatible($property)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if a single property is compatible with deck usage.
	 *
	 * @param mixed $property The property definition to check
	 *
	 * @return bool True if compatible, false otherwise
	 */
	private function isPropertyCompatible(mixed $property): bool
	{
		if (!is_array($property)) {
			return true;
		}

		// Check for incompatible type
		if (isset($property['type']) && in_array($property['type'], self::INCOMPATIBLE_TYPES, true)) {
			return false;
		}

		// Check for incompatible $ref
		if (isset($property['$ref']) && in_array($property['$ref'], self::INCOMPATIBLE_REFS, true)) {
			return false;
		}

		// Check nested properties (for object types)
		if (isset($property['properties']) && is_array($property['properties'])) {
			foreach ($property['properties'] as $nestedProperty) {
				if (!$this->isPropertyCompatible($nestedProperty)) {
					return false;
				}
			}
		}

		// Check array items
		if (isset($property['items']) && is_array($property['items'])) {
			if (!$this->isPropertyCompatible($property['items'])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the list of incompatible properties in a schema.
	 *
	 * @param array<string,mixed> $schema The schema array to check
	 *
	 * @return array<string> Array of property names that are incompatible
	 */
	public function getIncompatibleProperties(array $schema): array
	{
		$incompatible = [];

		if (!isset($schema['properties']) || !is_array($schema['properties'])) {
			return $incompatible;
		}

		foreach ($schema['properties'] as $propertyName => $property) {
			if (!$this->isPropertyCompatible($property)) {
				$incompatible[] = $propertyName;
			}
		}

		return $incompatible;
	}

	/**
	 * Get a list of the property types that are incompatible with deck usage.
	 *
	 * @return array<string>
	 */
	public function getIncompatibleTypes(): array
	{
		return self::INCOMPATIBLE_TYPES;
	}

	/**
	 * Check if a deck schema (referenced by name) is compatible.
	 *
	 * @param string $schemaName Name of the schema to check
	 *
	 * @return bool True if the deck schema is compatible
	 */
	public function isDeckSchemaCompatible(string $schemaName): bool
	{
		if ($this->schemaFetcher === null) {
			return false; // Cannot validate without SchemaFetcher
		}

		try {
			$schema = $this->schemaFetcher->fetchSchema($schemaName);

			return $this->isCompatible($schema->toArray());
		} catch (\Exception $e) {
			return false; // Schema not found or invalid
		}
	}

	/**
	 * Get incompatible properties from a deck schema (referenced by name).
	 *
	 * @param string $schemaName Name of the schema to check
	 *
	 * @return array<string> Array of incompatible property names
	 */
	public function getDeckSchemaIncompatibleProperties(string $schemaName): array
	{
		if ($this->schemaFetcher === null) {
			return []; // Cannot validate without SchemaFetcher
		}

		try {
			$schema = $this->schemaFetcher->fetchSchema($schemaName);

			return $this->getIncompatibleProperties($schema->toArray());
		} catch (\Exception $e) {
			error_log("DeckCompatibilityChecker: Exception getting incompatible properties for '$schemaName': " . $e->getMessage());

			return []; // Schema not found or invalid
		}
	}
}
