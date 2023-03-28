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
     * @param array  $propertySchema
     * @param mixed  $value
     *
     * @throws \DomainException
     * @throws \UnexpectedValueException
     *
     * @return PropertyData
     */
    public function generateProperty(array $propertySchema, mixed $value): PropertyData
    {
        if ($value === null && isset($propertySchema['default'])) {
            // Set the value from the schema default
            $value = $propertySchema['default'];
        }

        $type = $propertySchema['type'] ?? basename($propertySchema['$ref'], StorageRepository::FILE_EXT);

        $className = 'TotalCMS\\Domain\\Property\\Data\\' . ucfirst($type) . 'Data';
        if (!class_exists($className)) {
            throw new \UnexpectedValueException('Unknown property type for object.');
        }
        $property = null === $value ? new $className() : new $className($value);

        if (!$property instanceof PropertyData) {
            throw new \DomainException('Error creating property for object.');
        }

        return $property;
    }
}
