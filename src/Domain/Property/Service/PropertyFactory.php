<?php

namespace App\Domain\Property\Service;

use App\Domain\Property\Data\PropertyData;
use App\Domain\Storage\StorageRepository;
use DomainException;
use UnexpectedValueException;

/**
 * Service.
 */
final class PropertyFactory
{
    /**
     * create a property object.
     *
     * @param string $propertyName
     * @param array  $propertySchema
     * @param mixed  $value
     *
     * @throws DomainException
     * @throws UnexpectedValueException
     *
     * @return PropertyData
     */
    public function generateProperty(string $propertyName, array $propertySchema, mixed $value): PropertyData
    {
        if ($value === null && isset($propertySchema['default'])) {
            // Set the value from the schema default
            $value = $propertySchema['default'];
        }

        $type = $propertySchema['type'] ?? basename($propertySchema['$ref'], StorageRepository::FILE_EXT);

        $className = 'App\\Domain\\Property\\Data\\' . ucfirst($type) . 'Data';
        if (!class_exists($className)) {
            throw new UnexpectedValueException('Unknown property type for object.');
        }
        $property = null === $value ? new $className($propertyName) : new $className($propertyName, $value);

        if (!$property instanceof PropertyData) {
            throw new DomainException('Error creating property for object.');
        }

        return $property;
    }
}
