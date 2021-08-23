<?php

namespace App\Domain\Object\Data;

use Tightenco\Collect\Support\Collection;

/**
 * Data object.
 */
final class ObjectData
{
    public string $id;

    /** @var Collection<object> */
    public Collection $properties;

    /**
     * Output object to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->properties->toArray();
    }
}
