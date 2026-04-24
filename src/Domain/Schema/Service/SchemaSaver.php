<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

readonly class SchemaSaver
{
	public function __construct(
		private SchemaRepository $storage,
		private SchemaFactory $factory,
		private SchemaValidator $validator,
		private SchemaFetcher $schemaFetcher,
		private EventDispatcher $eventDispatcher,
	) {
	}

	/**
	 * @param array<string,mixed> $schemaData
	 *
	 * @throws \UnexpectedValueException
	 */
	public function updateSchema(string $schemaId, array $schemaData): SchemaData
	{
		if ($schemaId !== $schemaData['id']) {
			throw new \UnexpectedValueException('Schema ID does not match');
		}

		// Make sure the schema exists
		$this->storage->getSchema($schemaId);
		$schema = $this->saveSchema($schemaData);

		return $schema;
	}

	/**
	 * Save a schema.
	 *
	 * @param array<string,mixed> $schemaData
	 *
	 * @throws \InvalidArgumentException
	 * @throws \UnexpectedValueException
	 */
	public function saveSchema(array $schemaData): SchemaData
	{
		// Validate required input structure
		if (!isset($schemaData['properties'])) {
			throw new \InvalidArgumentException('Schema data must contain a "properties" key');
		}

		if (!is_array($schemaData['properties'])) {
			throw new \InvalidArgumentException('Schema "properties" must be an array');
		}

		// Check for reserved schema names early, before processing
		$reservedIds = $this->storage->reservedSchemasIds();
		if (isset($schemaData['id']) && (in_array($schemaData['id'], $reservedIds) || in_array($schemaData['id'], SchemaData::RESERVED_NAMES))) {
			throw new \UnexpectedValueException("Schema type ({$schemaData['id']}) is reserved", 1);
		}

		$schemaData['properties'] = self::propertyTypeToRef($schemaData['properties']);
		$schemaData['properties'] = self::normalizeDefaultValues($schemaData['properties']);
		$schemaData               = self::sanitizeRequiredAndIndex($schemaData, $this->getInheritedPropertyNames($schemaData));
		$schema                   = $this->factory->generateSchema($schemaData);

		if ($schema->id === '') {
			throw new \UnexpectedValueException('Schema ID is required: ' . json_encode($schemaData), 1);
		}

		// Ensure that the ID is required and indexed
		if (!in_array('id', $schema->required)) {
			$schema->required[] = 'id';
		}
		if (!in_array('id', $schema->index)) {
			$schema->index[] = 'id';
		}

		// This is not in Repository in order to prevent circular dependency
		if ($this->validator->validateSchema($schema->toArray()) === false) {
			throw new \UnexpectedValueException('Invalid Schema data provided');
		}

		$this->storage->saveSchema($schema);

		$this->eventDispatcher->dispatch('schema.saved', [
			'schema' => $schema->id,
		]);

		return $schema;
	}

	/**
	 * Sanitize required and index arrays to only contain existing properties.
	 *
	 * @param array<string,mixed> $schemaData
	 * @param array<string> $inheritedProperties Property names from inherited schemas
	 *
	 * @return array<string,mixed>
	 */
	public static function sanitizeRequiredAndIndex(array $schemaData, array $inheritedProperties = []): array
	{
		if (!isset($schemaData['properties']) || !is_array($schemaData['properties'])) {
			return $schemaData;
		}

		$validProperties = array_merge(array_keys($schemaData['properties']), $inheritedProperties);

		// Sanitize required array
		if (isset($schemaData['required']) && is_array($schemaData['required'])) {
			$schemaData['required'] = array_values(array_filter(
				$schemaData['required'],
				fn ($prop): bool => in_array($prop, $validProperties, true)
			));
		}

		// Sanitize index array
		if (isset($schemaData['index']) && is_array($schemaData['index'])) {
			$schemaData['index'] = array_values(array_filter(
				$schemaData['index'],
				fn ($prop): bool => in_array($prop, $validProperties, true)
			));
		}

		return $schemaData;
	}

	/**
	 * @param array<string,array<string,mixed>> $properties
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function propertyTypeToRef(array $properties): array
	{
		// Convert property types to $ref when possible
		foreach ($properties as $key => $options) {
			if (isset($options['type']) && array_key_exists($options['type'], SchemaData::PROPERTY_TYPE_TO_REF)) {
				$properties[$key]['$ref'] = SchemaData::PROPERTY_TYPE_TO_REF[$options['type']];
				unset($properties[$key]['type']);
			}
		}

		return $properties;
	}

	/**
	 * Normalize default values - convert string booleans to actual booleans.
	 *
	 * @param array<string,array<string,mixed>> $properties
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function normalizeDefaultValues(array $properties): array
	{
		foreach ($properties as $key => $options) {
			// Check if this property has a default value
			if (!isset($options['default'])) {
				continue;
			}

			// Check if this is a boolean field type
			$isBooleanField = false;

			// Check by type
			if (isset($options['type']) && $options['type'] === 'boolean') {
				$isBooleanField = true;
			}

			// Check by field type (toggle, checkbox)
			if (isset($options['field']) && in_array($options['field'], ['toggle', 'checkbox'], true)) {
				$isBooleanField = true;
			}

			// Convert string "true"/"false" to boolean for boolean fields
			if ($isBooleanField && is_string($options['default'])) {
				$default = strtolower($options['default']);
				if ($default === 'true' || $default === '1') {
					$properties[$key]['default'] = true;
				} elseif ($default === 'false' || $default === '0') {
					$properties[$key]['default'] = false;
				}
			}
		}

		return $properties;
	}

	/**
	 * Extract property type from a property definition.
	 * Uses the reverse of PROPERTY_TYPE_TO_REF mapping.
	 *
	 * @param array<string,mixed> $propertyDef
	 */
	public static function extractPropertyType(array $propertyDef): string
	{
		// Try to extract from $ref by doing reverse lookup
		if (isset($propertyDef['$ref']) && is_string($propertyDef['$ref'])) {
			// Reverse lookup in PROPERTY_TYPE_TO_REF
			$type = array_search($propertyDef['$ref'], SchemaData::PROPERTY_TYPE_TO_REF, true);
			if ($type !== false) {
				return $type;
			}
		}

		// Fall back to type field
		if (isset($propertyDef['type']) && is_string($propertyDef['type'])) {
			return $propertyDef['type'];
		}

		// Final fallback to field type
		return $propertyDef['field'] ?? 'text';
	}

	/**
	 * Get property names from inherited schemas.
	 *
	 * @param array<string,mixed> $schemaData
	 *
	 * @return array<string>
	 */
	private function getInheritedPropertyNames(array $schemaData): array
	{
		$inheritFrom = $schemaData['inheritFrom'] ?? [];

		if (!is_array($inheritFrom) || $inheritFrom === []) {
			return [];
		}

		$inherited = [];

		foreach ($inheritFrom as $parentId) {
			try {
				$parentSchema = $this->schemaFetcher->fetchRawSchema((string)$parentId);
				$inherited    = array_merge($inherited, array_keys($parentSchema->properties));
			} catch (\Exception) {
				continue;
			}
		}

		return array_unique($inherited);
	}

}
