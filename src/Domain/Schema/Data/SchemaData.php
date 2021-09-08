<?php

namespace App\Domain\Schema\Data;

/**
 * Data object.
 */
final class SchemaData
{
    // public string $anchor;
    public string $title = '';
    public string $description = '';
    public string $type = '';

    /** @var array<string> */
    public array $index = [];
    /** @var array<string> */
    public array $required = [];
    /** @var array<array> */
    public array $properties = [];
}
