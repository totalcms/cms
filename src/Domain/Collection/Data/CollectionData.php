<?php

namespace App\Domain\Collection\Data;

use Selective\ArrayReader\ArrayReader;
use UnexpectedValueException;

/**
 * Data object.
 */
final class CollectionData
{
    public string $name;
    public string $schema;
    public string $url;

    /**
     * Named constructor.
     *
     * @param array{name:string,schema:string} $array The array with data
     *
     * @return self
     */
    public static function fromArray(array $array): self
    {
        $data = new ArrayReader($array);

        $name = $data->findString('name');
        $schema = $data->findString('schema');
        $url = $data->findString('url');

        if (empty($name) || empty($schema)) {
            throw new UnexpectedValueException('Failed to create collection from array');
        }

        $collection = new self();
        $collection->name = $name;
        $collection->schema = $schema;
        $collection->url = $url ?? '';

        return $collection;
    }
}
