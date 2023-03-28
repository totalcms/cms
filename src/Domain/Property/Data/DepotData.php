<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class DepotData extends PropertyData
{
    public array $files = [];

    public function __construct(array $files = [])
    {
        $this->files = $files;
    }

    public function transform(): array
    {
        return $this->files;
    }
}
