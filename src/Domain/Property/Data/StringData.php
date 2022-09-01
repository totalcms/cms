<?php

namespace App\Domain\Property\Data;

/**
 * String type property data.
 */
class StringData extends PropertyData
{
    public string $id;
    public string $text;

    public function __construct(string $id, string $text)
    {
        $this->id   = $id;
        $this->text = $text;
    }

    public function transform(): string
    {
        return $this->text;
    }
}
