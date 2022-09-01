<?php

namespace App\Domain\Property\Data;

/**
 * Color property data.
 */
class ColorData extends PropertyData
{
    public string $h;
    public string $s;
    public string $l;
    public string $a;

    public function __construct(string $id, array $color)
    {
        $this->id = $id;
        $this->h  = $color['h'];
        $this->s  = $color['s'];
        $this->l  = $color['l'];
        $this->a  = $color['a'];
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
        return sprintf('hsla(%g,%g%%,%g%%,%g)', $this->h, $this->s, $this->l, $this->a);
    }
}
