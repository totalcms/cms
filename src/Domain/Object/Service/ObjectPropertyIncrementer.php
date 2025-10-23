<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Schema\Service\SchemaFetcher;

readonly class ObjectPropertyIncrementer
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/**
	 * Increment a numeric property by the specified amount.
	 *
	 * @param string $collection The collection name
	 * @param string $id The object ID
	 * @param string $property The property name
	 * @param int $amount The amount to increment by (default: 1)
	 *
	 * @return array{property: string, value: int|float} The property name and new value
	 *
	 * @throws \InvalidArgumentException If property is not numeric or doesn't exist
	 * @throws \OutOfRangeException If the new value exceeds min/max bounds
	 */
	public function incrementProperty(string $collection, string $id, string $property, int $amount = 1): array
	{
		return $this->adjustProperty($collection, $id, $property, $amount);
	}

	/**
	 * Decrement a numeric property by the specified amount.
	 *
	 * @param string $collection The collection name
	 * @param string $id The object ID
	 * @param string $property The property name
	 * @param int $amount The amount to decrement by (default: 1)
	 *
	 * @return array{property: string, value: int|float} The property name and new value
	 *
	 * @throws \InvalidArgumentException If property is not numeric or doesn't exist
	 * @throws \OutOfRangeException If the new value exceeds min/max bounds
	 */
	public function decrementProperty(string $collection, string $id, string $property, int $amount = 1): array
	{
		return $this->adjustProperty($collection, $id, $property, -$amount);
	}

	/**
	 * Adjust a numeric property by the specified amount.
	 *
	 * @param string $collection The collection name
	 * @param string $id The object ID
	 * @param string $property The property name
	 * @param int $amount The amount to adjust by (positive for increment, negative for decrement)
	 *
	 * @return array{property: string, value: int|float} The property name and new value
	 *
	 * @throws \InvalidArgumentException If property is not numeric or doesn't exist
	 * @throws \OutOfRangeException If the new value exceeds min/max bounds
	 */
	private function adjustProperty(string $collection, string $id, string $property, int $amount): array
	{
		// Fetch the object
		$object = $this->objectFetcher->fetchObject($collection, $id);

		// Check if property exists
		$propertyData = $object->properties->get($property);
		if ($propertyData === null) {
			throw new \InvalidArgumentException("Property '{$property}' does not exist on object");
		}

		// Get the transformed value
		$currentValue = $propertyData->transform();

		// Validate that it's numeric
		if (!is_numeric($currentValue)) {
			throw new \InvalidArgumentException("Property '{$property}' is not numeric");
		}

		// Get schema to check for min/max settings
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

		$min = null;
		$max = null;

		// Check if property has min/max settings in schema
		if (isset($schema->properties[$property])) {
			$propertyDef = $schema->properties[$property];

			if (is_array($propertyDef)) {
				$min = $propertyDef['min'] ?? null;
				$max = $propertyDef['max'] ?? null;
			}
		}

		// Calculate new value
		$newValue = $currentValue + $amount;

		// Validate against min/max
		if ($min !== null && $newValue < $min) {
			throw new \OutOfRangeException(
				"New value {$newValue} is below minimum allowed value {$min} for property '{$property}'"
			);
		}

		if ($max !== null && $newValue > $max) {
			throw new \OutOfRangeException(
				"New value {$newValue} exceeds maximum allowed value {$max} for property '{$property}'"
			);
		}

		// Update the object using toArray() and updateObject()
		$data             = $object->toArray();
		$data[$property] = $newValue;

		// Save the object
		$this->objectUpdater->updateObject($collection, $id, $data);

		// Return just the property and value
		return [
			'property' => $property,
			'value'    => $newValue,
		];
	}
}
