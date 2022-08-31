<?php

namespace App\Domain\Index\Data;

use Illuminate\Support\Collection;

/**
 * Data object.
 */
final class IndexData
{
    /** @var Collection<int,array> */
    public Collection $objects;

    public function __construct(array $objects = [])
    {
        $this->objects = collect($objects);
    }
}
