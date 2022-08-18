<?php

namespace App\Domain\Object\Service;

use App\Domain\Object\Data\ObjectData;
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

    public function __construct(
        SchemaFetcher $schemaFetcher,
        SchemaValidator $validator,
    ) {
        $this->schemaFetcher = $schemaFetcher;
        $this->validator     = $validator;
    }

    /**
     * create a schema object.
     *
     * @param string $collection
     * @param string $objectJson
     *
     * @throws UnexpectedValueException
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
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

            $value = null;

            if (isset($objectData[$property])) {
                // Set the value from the JSON
                $value = $objectData[$property];
            } elseif (isset($propertySchema['default'])) {
                // Set the value from the schema default
                $value = $propertySchema['default'];
            } elseif (isset($propertySchema['$ref'])) {
                // TODO: $ref is a property object.
                $value = $objectData[$property] ?? [];
            }

            if ($value !== null) {
                $properties[$property] = $value;
            }
        }

        return $properties;
    }
}
