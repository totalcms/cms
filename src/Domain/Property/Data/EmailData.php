<?php

namespace App\Domain\Property\Data;

use InvalidArgumentException;

/**
 * Email type property data.
 */
class EmailData extends PropertyData
{
    public string $email;

    public function __construct(string $id, string $email)
    {
        $this->id    = $id;
        $this->email = self::cleanEmail($email);
    }

    private static function cleanEmail(string $email): string
    {
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);

        if ($email === false || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }

        return $email;
    }

    public function transform(): string
    {
        return (string)$this;
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
