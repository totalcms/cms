<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Color property data.
 */
class ColorData extends PropertyData
{
    public float $h;
    public float $s;
    public float $l;
    public float $a;

    public function __construct(array $color)
    {
        $this->h  = floatval($color['h']);
        $this->s  = floatval($color['s']);
        $this->l  = floatval($color['l']);
        $this->a  = isset($color['a']) ? floatval($color['a']) : 1;
    }

    public function transform(): array
    {
        return [
            'h' => $this->h,
            's' => $this->s,
            'l' => $this->l,
            'a' => $this->a,
        ];
    }

    public function __toString(): string
    {
        return sprintf('hsla(%f,%f%%,%f%%,%f)', $this->h, $this->s, $this->l, $this->a);
    }
}
