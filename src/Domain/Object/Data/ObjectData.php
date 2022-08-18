<?php

namespace App\Domain\Object\Data;

use Cocur\Slugify\Slugify;
use Illuminate\Support\Collection;

/**
 * Data collection object.
 */
class ObjectData
{
    public string $id;
    /** @var Collection<string, mixed> */
    public Collection $properties;

    public function __construct(string $id, array $properties)
    {
        $this->id         = (new Slugify())->slugify($id);
        $this->properties = new Collection($properties);
    }

    public function toArray(): array
    {
        $base = ['id' => $this->id];

        return array_merge($base, $this->properties->toArray());
    }
}
