<?php

namespace App\Domain\Property\Data;

/**
 * String type property data.
 */
class StringData extends PropertyData
{
    public string $text;

    public function __construct(string $id, string $text)
    {
        $this->id   = $id;
        $this->text = $text;
    }

    public function transform(): string
    {
        return (string)$this;
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
