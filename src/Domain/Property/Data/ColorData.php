<?php

namespace TotalCMS\Domain\Property\Data;

use matthieumastadenis\couleur\ColorFactory;
use matthieumastadenis\couleur\ColorSpace;

/**
 * Color property data.
 */
class ColorData extends PropertyData
{
    public string $hex;
    public array $oklch;

    public function __construct(string|array $color)
    {
        if (is_string($color)) {
            $this->hex   = $color;
            $this->oklch = self::hexToOklch($this->hex);

            return;
        }
        $this->hex   = $color['hex'] ?? '#000000';
        $this->oklch = $color['oklch'] ?? self::hexToOklch($this->hex);
    }

    public static function hexToOklch(string $hex): array
    {
        $oklch = ColorFactory::newOkLch($hex, ColorSpace::HexRgb);
        if ($oklch === null) {
            return ['l'=>0, 'c'=>0, 'h'=>0]; // black
        }
        $coordinates = array_map(fn ($c) => round($c, 3), $oklch->coordinates());

        return [
            'l' => $coordinates[0],
            'c' => $coordinates[1],
            'h' => $coordinates[2],
        ];
    }

    public function transform(): array
    {
        return [
            'hex'   => $this->hex,
            'oklch' => $this->oklch,
        ];
    }

    public function __toString(): string
    {
        return sprintf('oklch(%f%% %f% %f%)', $this->oklch['l'], $this->oklch['c'], $this->oklch['h']);
    }
}
