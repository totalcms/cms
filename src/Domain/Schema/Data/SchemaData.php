<?php

namespace App\Domain\Schema\Data;

/**
 * Data object.
 */
final class SchemaData
{
    public string $collection;
    /** @var array<string> */
    public array $index;
    /** @var array<string> */
    public array $required;
    /** @var array<array{type:string,fieldset:string}> */
    public array $properties;

    /**
     * Named constructor.
     *
     * @param mixed[] $array The schema array
     *
     * @return self
     */
    public static function fromArray(array $array) : self
    {
        $data = new ArrayReader($array);

        $collection   = $data->findString('collection');
        $index        = $data->findArray('index');
        $required     = $data->findArray('required');
        $properties   = $data->findArray('properties');

        if (empty($name) || empty($schema)) {
            throw new UnexpectedValueException('Failed to create collection from array');
        }

        $collection          = new self();
        $collection->name    = $name;
        $collection->schema  = $schema;
        $collection->url     = $url ?? '';

        return $collection;
    }
}
