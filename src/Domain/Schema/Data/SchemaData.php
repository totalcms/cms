<?php

namespace App\Domain\Schema\Data;

/**
 * Schema Data object.
 */
final class SchemaData
{
    // Storing the JSON as array to avoid having to deal with
    // $id and $schemas properties.
    public array $schema;
    public string $type;
}
