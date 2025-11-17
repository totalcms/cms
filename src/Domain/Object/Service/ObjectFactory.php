<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\PropertyFactory;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Service.
 */
readonly class ObjectFactory
{
	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private PropertyFactory $propertyFactory,
		private AutogenIdService $autogenIdService,
	) {
	}

	/**
	 * create a schema object.
	 *
	 * @param array<string,mixed> $objectData
	 *
	 * @throws \UnexpectedValueException
	 */
	public function generateObject(string $collection, array $objectData): ObjectData
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

		// Handle ID generation if not provided
		if (!array_key_exists('id', $objectData) || empty($objectData['id'])) {
			$objectData['id'] = $this->generateIdIfNeeded($collection, $objectData, $schema);

			if (empty($objectData['id'])) {
				throw new \UnexpectedValueException('Object data must contain an ID or schema must have autogen settings for ID field.');
			}
		}

		$properties = $this->generateProperties($objectData, $schema);

		// Dynamically load object data based on the schema type
		// Not sure if this is really needed but it's a good idea to have it.
		$className = 'TotalCMS\\Domain\\Object\\Data\\' . ucfirst($schema->id) . 'Data';
		if (!class_exists($className)) {
			$className = ObjectData::class;
		}
		$object = new $className($objectData['id'], $properties);

		if (!$object instanceof ObjectData) {
			throw new \DomainException('Unknown error creating object.');
		}

		return $object;
	}

	/**
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,mixed>
	 */
	private function generateProperties(array $objectData, SchemaData $schema): array
	{
		$properties = [];

		// Loop through the schema properties and add them to the object properties.
		foreach ($schema->properties as $property => $propertySchema) {
			if ($property === 'id') {
				// No use storing the ID a second time in the object properties.
				continue;
			}
			if (!array_key_exists($property, $objectData)) {
				// Skip missing properties during object loading (even if required)
				// This allows objects to be loaded for indexing even if they're missing
				// fields that were added later to the schema. Validation happens during
				// save/update, not during read/index operations.
				// Only include the property if it has a default value in the schema.
				if (!array_key_exists('default', $propertySchema)) {
					continue;
				}

				$objectData[$property] = null;
			}

			$value = $objectData[$property];

			$properties[$property] = $this->propertyFactory->generateProperty($propertySchema, $value);
		}

		return $properties;
	}

	/**
	 * Generate ID using autogen settings if configured.
	 *
	 * @param array<string,mixed> $objectData
	 */
	private function generateIdIfNeeded(string $collection, array $objectData, SchemaData $schema): string
	{
		// Check if ID field has autogen settings
		$idProperty     = $schema->properties['id'] ?? [];
		$autogenPattern = $idProperty['settings']['autogen'] ?? null;

		if (!empty($autogenPattern)) {
			return $this->autogenIdService->generateId($autogenPattern, $collection, $objectData);
		}

		return '';
	}
}
