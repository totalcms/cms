<?php

namespace App\Domain\Property\Data;

/**
 * String type property data.
 */
class DepotData extends PropertyData
{
    public array $files = [];

    public function __construct(string $id, array $files = [])
    {
        $this->id    = $id;
        $this->files = $files;
    }

    public function transform(): array
    {
        return $this->files;
    }
}
