<?php

namespace App\Domain\Object\Data;

use Illuminate\Support\Collection;

/**
 * Data collection object.
 */
final class ObjectData
{
    public string $id;
    public Collection $properties;

    public function __construct()
    {
        $this->properties = new Collection();
    }

    public function toArray(): array
    {
        $base = ["id" => $this->id];
        return array_merge($base, $this->properties->toArray());
    }
}
