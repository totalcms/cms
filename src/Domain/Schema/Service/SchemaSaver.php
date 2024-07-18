<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

/**
 * Service.
 */
final class SchemaSaver
{
	private SchemaValidator $validator;
	private SchemaRepository $storage;
	private SchemaFactory $factory;

	public function __construct(SchemaRepository $storage, SchemaFactory $factory, SchemaValidator $validator)
	{
		$this->storage   = $storage;
		$this->factory   = $factory;
		$this->validator = $validator;
	}

	/**
	 * Save a schema.
	 *
	 * @param array<string,mixed> $schemaData
	 *
	 * @throws \UnexpectedValueException
	 *
	 * @return SchemaData
	 */
	public function saveSchema(array $schemaData): SchemaData
	{
		$schemaData['properties'] = $this->propertyTypeToRef($schemaData['properties']);
		$schema = $this->factory->generateSchema($schemaData);

		if (in_array($schema->id, SchemaData::RESERVED_SCHEMAS) || in_array($schema->id, $this->storage->reservedSchemasIds())) {
			throw new \UnexpectedValueException("Schema type ({$schema->id}) is reserved", 1);
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
	 * @return array<string,array<string,mixed>>
	 */
	private function propertyTypeToRef(array $properties): array
	{
		// Convert property types to $ref when possible
		foreach ($properties as $key => $options) {
			if (isset($options['type']) && in_array($options['type'], SchemaData::PROPERTY_TYPES)) {
				$properties[$key]['$ref'] = SchemaData::PROPERTY_TYPE_TO_REF[$options['type']];
				unset($properties[$key]['type']);
			}
		}
		return $properties;
	}
}
