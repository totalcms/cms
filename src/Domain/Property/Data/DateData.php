<?php

namespace TotalCMS\Domain\Property\Data;

use DateTime;

/**
 * Date property data.
 */
class DateData extends PropertyData
{
    public string $date;

    public function __construct(string $date)
    {
        $this->date = self::cleanDate($date);
    }

    private static function cleanDate(string $date): string
    {
        // TODO: add timezone configuration support

        $date = new DateTime($date);

        return $date->format('c');
    }

    public function transform(): string
    {
        return (string)$this;
    }

    public function __toString(): string
    {
        return $this->date;
    }
}
