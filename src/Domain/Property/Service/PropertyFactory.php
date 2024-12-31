<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Storage\StorageRepository;

/**
 * Service.
 */
final class PropertyFactory
{
	/**
	 * create a property object.
	 *
	 * @param array<string,mixed>  $propertySchema
	 * @param mixed  $value
	 *
	 * @throws \DomainException
	 * @throws \UnexpectedValueException
	 *
	 * @return PropertyData
	 */
	public function generateProperty(array $propertySchema, mixed $value): PropertyData
	{
		$type = $propertySchema['type'] ?? basename($propertySchema['$ref'], StorageRepository::FILE_EXT);

		$className = 'TotalCMS\\Domain\\Property\\Data\\' . ucfirst($type) . 'Data';
		if (!class_exists($className)) {
			throw new \UnexpectedValueException('Unknown property type for object.');
		}

		if (isset($propertySchema['default'])) {
			$value = $className::defaultValue($value, $propertySchema['default']);
		}

		$settings = $propertySchema['settings'] ?? [];

		$property = null === $value ? new $className(settings: $settings) : new $className($value, $settings);

		if (!$property instanceof PropertyData) {
			throw new \DomainException('Error creating property for object.');
		}

		return $property;
	}
}
