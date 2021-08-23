<?php

namespace App\Domain\Schema\Data;

use Selective\ArrayReader\ArrayReader;
use UnexpectedValueException;

/**
 * Data object.
 */
final class SchemaData
{
    // public string $anchor;
    public string $title;
    public string $description;
    public string $type;

    /** @var array<string> */
    public array $index;
    /** @var array<string> */
    public array $required;
    /** @var array<array> */
    public array $properties;

    /**
     * Named constructor.
     *
     * @param mixed[] $array The schema array
     *
     * @return self
     */
    public static function fromArray(array $array): self
    {
        $data = new ArrayReader($array);

        // $anchor      = $data->findString('$anchor');
        $title = $data->findString('title') ?? '';
        $description = $data->findString('description') ?? '';
        $type = $data->findString('type') ?? 'object';
        $index = $data->findArray('index') ?? [];
        $required = $data->findArray('required') ?? [];
        $properties = $data->findArray('properties');

        if (empty($properties)) {
            throw new UnexpectedValueException('Failed to create schema from array. No properties defined.');
        }

        $schema = new self();
        // $schema->anchor      = $anchor;
        $schema->title = $title;
        $schema->description = $description;
        $schema->type = $type;
        $schema->index = $index;
        $schema->required = $required;
        $schema->properties = $properties;

        return $schema;
    }
}
