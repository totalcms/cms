<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Color property data.
 */
class ColorData extends PropertyData
{
    public string $hex;
    public int $alpha;
    public array $oklch;

    public function __construct(array $color)
    {
        $this->hex   = $color['hex'];
        $this->alpha = intval($color['alpha']);
        $this->oklch = $color['oklch'];
    }

    public function transform(): array
    {
        return [
            'hex'   => $this->hex,
            'alpha' => $this->alpha,
            'oklch' => $this->oklch,
        ];
    }

    public function __toString(): string
    {
        return sprintf('oklch(%f% %f% %f% / %f%)', $this->oklch['l'], $this->oklch['c'], $this->oklch['h'], $this->alpha);
    }
}
