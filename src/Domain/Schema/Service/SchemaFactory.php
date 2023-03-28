<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Data\SchemaData;
use UnexpectedValueException;

/**
 * Service.
 */
final class SchemaFactory
{
    private const SCHEMA_ID_EXT = '.json';

    /**
     * create a schema object.
     *
     * @param string $schemaJson
     * @param string $type
     *
     * @throws UnexpectedValueException
     *
     * @return SchemaData
     */
    public static function generateSchema(string $schemaJson, string $type = ''): SchemaData
    {
        $schema = json_decode($schemaJson, true);

        if (strpos($schema['$id'], self::SCHEMA_ID_EXT) === false) {
            throw new UnexpectedValueException('Malformed schema data provided', 1);
        }

        if (empty($type)) {
            $type = basename($schema['$id'], self::SCHEMA_ID_EXT);
        }

        $schemaData         = new SchemaData();
        $schemaData->schema = $schema;
        $schemaData->type   = $type;

        return $schemaData;
    }
}
