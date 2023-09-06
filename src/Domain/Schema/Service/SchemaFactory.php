<?php

namespace TotalCMS\Domain\Schema\Service;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Service.
 */
final class SchemaFactory
{
    private Serializer $serializer;

    public function __construct()
    {
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * create a schema object.
     *
     * @param string $schemaJson
     * @param string $id
     *
     * @throws \UnexpectedValueException
     *
     * @return SchemaData
     */
    public function generateSchema(string $schemaJson): SchemaData
    {
        $schema = $this->serializer->deserialize($schemaJson, SchemaData::class, 'json');

        if (!$schema instanceof SchemaData) {
            throw new \UnexpectedValueException('Invalid Schema data provided');
        }

        return $schema;
    }
}
