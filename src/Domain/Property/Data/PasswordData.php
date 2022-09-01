<?php

namespace App\Domain\Property\Data;

/**
 * String type property data.
 */
class PasswordData extends PropertyData
{
    public string $hash;

    public function __construct(string $id, string $password)
    {
        $this->id   = $id;
        $this->hash = password_hash($password, PASSWORD_DEFAULT);
    }

    public function transform(): string
    {
        return (string)$this;
    }

    public function __toString(): string
    {
        return $this->hash;
    }
}
