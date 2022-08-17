<?php

namespace App\Domain\Schema\Data;

/**
 * Schema Data object.
 */
final class SchemaData
{
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

    // Storing the JSON as array to avoid having to deal with
    // $id and $schemas properties.
    public array $schema;
    public string $type;

    public function toJson(): string
    {
        $json = json_encode($this->schema);
        return $json === false ? '' : $json;
    }
}
