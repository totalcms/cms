<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Time type property data.
 */
class TimeData extends PropertyData
{
    public string $time;

    public function __construct(string $time)
    {
        $this->time = $time;
    }

    public function transform(): string
    {
        return (string)$this;
    }

    public function __toString(): string
    {
        return $this->time;
    }
}
