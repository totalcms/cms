<?php

namespace App\Domain\Property\Data;

use InvalidArgumentException;

/**
 * String type property data.
 */
class SetData extends PropertyData
{
    public array $set;

    public function __construct(array $set)
    {
        if (!self::verifySet($set)) {
            throw new InvalidArgumentException('Set must be a set of simple objects');
        }
        $this->set = $set;
    }

    private static function verifySet(array $set): bool
    {
        if (!array_is_list($set)) {
            return false;
        }
        foreach ($set as $item) {
            if (!is_array($item)) {
                return false;
            }
            foreach ($item as $attribute) {
                if (!is_scalar($attribute)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function transform(): array
    {
        return $this->set;
    }
}
