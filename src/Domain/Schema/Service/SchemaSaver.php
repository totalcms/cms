<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

readonly class SchemaSaver
{
	public function __construct(
		private SchemaRepository $storage,
		private SchemaFactory $factory,
		private SchemaValidator $validator,
		private IndexBuilder $indexBuilder,
		private CollectionLister $collectionLister,
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

		$this->rebuildIndexforCollectionsWithSchema($schema->id);

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
		if (isset($schemaData['id']) && (in_array($schemaData['id'], SchemaData::RESERVED_SCHEMAS) || in_array($schemaData['id'], SchemaData::RESERVED_NAMES))) {
			throw new \UnexpectedValueException("Schema type ({$schemaData['id']}) is reserved", 1);
		}

		$schemaData['properties'] = self::propertyTypeToRef($schemaData['properties']);
		$schema                   = $this->factory->generateSchema($schemaData);

		if (!isset($schema->id)) {
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

		return $schema;
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

	private function rebuildIndexforCollectionsWithSchema(string $schemaId): void
	{
		$collections = $this->collectionLister->listAllCollections();

		foreach ($collections as $collection) {
			if ($collection->schema === $schemaId) {
				$this->indexBuilder->smartBuildIndex($collection->id);
			}
		}
	}
}
