<?php

namespace TotalCMS\Domain\Property\Data;

use InvalidArgumentException;

/**
 * List type property data.
 */
class ListData extends PropertyData
{
    public array $list;

    public function __construct(array $list)
    {
        if (!self::verifyList($list)) {
            throw new InvalidArgumentException('List must be a list');
        }
        $this->list = $list;
    }

    private static function verifyList(array $list): bool
    {
        if (!array_is_list($list)) {
            return false;
        }
        foreach ($list as $item) {
            if (!is_scalar($item)) {
                return false;
            }
        }

        return true;
    }

    public function transform(): array
    {
        return $this->list;
    }
}
