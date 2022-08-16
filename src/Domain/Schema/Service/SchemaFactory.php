<?php

namespace App\Domain\Schema\Service;

use App\Domain\Schema\Data\SchemaData;

/**
 * Service.
 */
final class SchemaFactory
{
    private const SCHEMA_ID_EXT  = '.json#';
    public const RESERVED_SCHEMAS = [
        "blog",
        "color",
        "date",
        "depot",
        "feed",
        "file",
        "gallery",
        "image",
        "number",
        "schema",
        "svg",
        "text",
        "toggle",
        "url",
    ];

    /**
     * create a schema object
     *
     * @param string $schemaJson
     * @param string $type
     *
     * @return SchemaData
     */
    public static function generateSchema(string $schemaJson, string $type = ''): SchemaData
    {
        $schema = json_decode($schemaJson, true);

        if (empty($type)) {
            $type = basename($schema['$id'], self::SCHEMA_ID_EXT);
        }

        $schemaData = new SchemaData();
        $schemaData->schema = $schema;
        $schemaData->type   = $type;

        return $schemaData;
    }
}
