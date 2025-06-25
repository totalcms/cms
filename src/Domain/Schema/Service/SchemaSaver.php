<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

final class SchemaSaver
{
	public function __construct(
		private SchemaRepository $storage,
		private SchemaFactory $factory,
		private SchemaValidator $validator,
		private IndexBuilder $indexBuilder,
		private CollectionLister $collectionLister,
	) {
		$this->storage   = $storage;
		$this->factory   = $factory;
		$this->validator = $validator;
	}

	/**
	 * @param array<string,mixed> $schemaData
	 *
	 * @throws \UnexpectedValueException
	 *
	 * @return SchemaData
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
	 * @throws \UnexpectedValueException
	 *
	 * @return SchemaData
	 */
	public function saveSchema(array $schemaData): SchemaData
	{
		$schemaData['properties'] = self::propertyTypeToRef($schemaData['properties']);
		$schema                   = $this->factory->generateSchema($schemaData);

		if (in_array($schema->id, SchemaData::RESERVED_SCHEMAS) || in_array($schema->id, $this->storage->reservedSchemasIds())) {
			throw new \UnexpectedValueException("Schema type ({$schema->id}) is reserved", 1);
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
