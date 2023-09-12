<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\PropertyFactory;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\CollectionSchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaValidator;

/**
 * Service.
 */
final class ObjectFactory
{
    private CollectionSchemaFetcher $schemaFetcher;
    private SchemaValidator $validator;
    private PropertyFactory $propertyFactory;

    public function __construct(
        CollectionSchemaFetcher $schemaFetcher,
        SchemaValidator $validator,
        PropertyFactory $propertyFactory,
    ) {
        $this->schemaFetcher   = $schemaFetcher;
        $this->validator       = $validator;
        $this->propertyFactory = $propertyFactory;
    }

    /**
     * create a schema object.
     *
     * @param string $collection
     * @param array $objectData
     *
     * @throws \UnexpectedValueException
     *
     * @return ObjectData
     */
    public function generateObject(string $collection, array $objectData): ObjectData
    {
        $schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

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

    private function generateProperties(array $objectData, SchemaData $schema): array
    {
        $properties = [];

        // Loop through the schema properties and add them to the object properties.
        foreach ($schema->properties as $property => $propertySchema) {
            if ($property === 'id') {
                // No use storing the ID a second time in the object properties.
                continue;
            }

            $value = $objectData[$property] ?? null;

            $properties[$property] = $this->propertyFactory->generateProperty($propertySchema, $value);
        }

        return $properties;
    }
}
