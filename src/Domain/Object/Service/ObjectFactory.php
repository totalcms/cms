<?php

namespace App\Domain\Object\Service;

use App\Domain\Object\Data\ObjectData;
use App\Domain\Property\Service\PropertyFactory;
use App\Domain\Schema\Data\SchemaData;
use App\Domain\Schema\Service\SchemaFetcher;
use App\Domain\Schema\Service\SchemaValidator;
use DomainException;
use UnexpectedValueException;

/**
 * Service.
 */
final class ObjectFactory
{
    private SchemaFetcher $schemaFetcher;
    private SchemaValidator $validator;
    private PropertyFactory $propertyFactory;

    public function __construct(
        SchemaFetcher $schemaFetcher,
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
     * @param string $objectJson
     *
     * @throws UnexpectedValueException
     *
     * @return ObjectData
     */
    public function generateObject(string $collection, string $objectJson): ObjectData
    {
        $schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

        if ($this->validator->validateSchema($objectJson, $schema->type) === false) {
            throw new UnexpectedValueException('Invalid object data provided. Failed schema validation.', 1);
        }

        $objectData = json_decode($objectJson, true);
        $properties = $this->generateProperties($objectData, $schema);

        // Dynamically load object data based on the schema type
        // Not sure if this is really needed but it's a good idea to have it.
        $className = 'App\\Domain\\Object\\Data\\' . ucfirst($schema->type) . 'Data';
        if (!class_exists($className)) {
            $className = ObjectData::class;
        }
        $object = new $className($objectData['id'], $properties);

        if (!$object instanceof ObjectData) {
            throw new DomainException('Unknown error creating object.');
        }

        return $object;
    }

    private function generateProperties(array $objectData, SchemaData $schema): array
    {
        $properties = [];

        // Loop through the schema properties and add them to the object properties.
        foreach ($schema->schema['properties'] as $property => $propertySchema) {
            if ($property === 'id') {
                // No use storing the ID a second time in the object properties.
                continue;
            }

            $properties[$property] =
                $this->propertyFactory->generateProperty($property, $propertySchema, $objectData[$property]);
        }

        return $properties;
    }
}
