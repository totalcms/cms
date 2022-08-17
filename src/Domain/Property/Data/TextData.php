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

    /**
     * @param mixed $items The items
     */
    public function __construct($items)
    {
        $this->properties = new Collection($items);
    }

    public function toArray(): array
    {
        return $this->properties->toArray();
    }
}
