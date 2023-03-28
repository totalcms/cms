<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Boolean type property data.
 */
class BooleanData extends PropertyData
{
    public bool $status;

    public function __construct(bool $status)
    {
        $this->status = $status;
    }

    public function transform(): bool
    {
        return $this->status;
    }

    public function __toString(): string
    {
        return $this->status ? 'true' : 'false';
    }
}
