<?php

namespace App\Domain\Object\Service;

use App\Domain\Object\Data\ObjectData;
use App\Domain\Schema\Service\SchemaFetcher;
use App\Domain\Schema\Service\SchemaValidator;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use UnexpectedValueException;

/**
 * Service.
 */
final class ObjectFactory
{
    private SchemaFetcher $schemaFetcher;
    private SchemaValidator $validator;
    private Serializer $serializer;

    public function __construct(
        SchemaFetcher $schemaFetcher,
        SchemaValidator $validator,
    ) {
        $this->schemaFetcher = $schemaFetcher;
        $this->validator     = $validator;
        $this->serializer    = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * create a schema object
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

        $object = $this->serializer->deserialize($objectJson, ObjectData::class, 'json');

        if (!$object instanceof ObjectData) {
            throw new UnexpectedValueException('Invalid object data provided. Serialization failed.', 1);
        }

        $data = json_decode($objectJson, true);

        foreach ($schema->schema["properties"] as $property => $propertySchema) {
            if ($property === 'id') {
                // No use storing the ID a second time in the properties.
                continue;
            }
            // TODO: need to look for type or $ref. Type is simple. $ref is a property object.
            // switch ($propertySchema["type"]??"") {
            //     case 'string':
            //         $value = (string) $data[$property];
            //         break;
            //     case 'integer':
            //         $value = intval($data[$property]);
            //         break;
            //     case 'boolean':
            //         $value = boolval($data[$property]);
            //         break;
            //     default:
            //         $value = $data[$property];
            //         break;
            // }
            // Don't need to do a type check on the data since the schema validator did that.
            $object->properties->put($property, $data[$property]);
        }
        return $object;
    }
}


/*
Read in the schema for an object. For each property,
create a new property object and add it to the collection.
Needs to create property classes for each type of property.
Except for string, number, bool, etc.
*/
