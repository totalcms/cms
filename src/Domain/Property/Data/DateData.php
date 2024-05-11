<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Date property data.
 */
class DateData extends PropertyData
{
    public string $date;

    public const CREATION_DATE = 'onCreate';
    public const UPDATE_DATE   = 'onUpdate';

    public function __construct(string $date)
    {
        $this->date = empty($date) ? '' : self::cleanDate($date);
    }

    public static function defaultValue(mixed $value, mixed $default): mixed
    {
        if (isset($default)) {
            if (empty($value) && $default === self::CREATION_DATE) {
                $value = self::cleanDate();
            }
            if ($default === self::UPDATE_DATE) {
                $value = self::cleanDate();
            }
        }

        return $value;
    }

    private static function cleanDate(string $date = ''): string
    {
        // TODO: add timezone configuration support

        $date = new \DateTime($date);

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
