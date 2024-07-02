<?php

namespace TotalCMS\Domain\Property\Data;

use matthieumastadenis\couleur\ColorFactory;
use matthieumastadenis\couleur\ColorSpace;
use matthieumastadenis\couleur\utils\hexRgb;

/**
 * Color property data.
 */
class ColorData extends PropertyData
{
    public string $hex;
    /** @var array<string,float> */
    public array $oklch;

    /** @param string|array<string,mixed> $color */
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

    /** @param array<string,float> $oklch */
    public static function oklchToHex(array $oklch): string
    {
        $hex = ColorFactory::newHexRgb([$oklch['l'], $oklch['c'], $oklch['h']], ColorSpace::OkLch);
        if ($hex === null) {
            return '#000000'; // black
        }
        $coordinates = $hex->coordinates();

        return hexRgb\stringify($coordinates[0], $coordinates[1], $coordinates[2], short: false);
    }

    /**
     * @param array<string,float> $oklch
     * @param array<string,mixed> $change
     *
     * @return array<string,float>
     */
    public static function oklchChange(array $oklch, array $change): array
    {
        $oklch = ColorFactory::newOkLch([$oklch['l'], $oklch['c'], $oklch['h']], ColorSpace::OkLch);
        if ($oklch === null) {
            return ['l' => 0, 'c' => 0, 'h' => 0]; // black
        }
        $oklch = $oklch->change(
            lightness: $change['l'] ?? null,
            chroma: $change['c'] ?? null,
            hue: $change['h'] ?? null
        );
        $coordinates = array_map(fn ($c) => round($c, 3), $oklch->coordinates());

        return [
            'l' => $coordinates[0],
            'c' => $coordinates[1],
            'h' => $coordinates[2],
        ];
    }

    /** @return array<string,float> */
    public static function hexToOklch(string $hex): array
    {
        $oklch = ColorFactory::newOkLch($hex, ColorSpace::HexRgb);
        if ($oklch === null) {
            return ['l' => 0, 'c' => 0, 'h' => 0]; // black
        }
        $coordinates = array_map(fn ($c) => round($c, 3), $oklch->coordinates());

        return [
            'l' => $coordinates[0],
            'c' => $coordinates[1],
            'h' => $coordinates[2],
        ];
    }

    /** @return array<string,int> */
    public static function hexToRgb(string $hex): array
    {
        $rgb = ColorFactory::newRgb($hex, ColorSpace::HexRgb);
        if ($rgb === null) {
            return ['r' => 0, 'g' => 0, 'b' => 0]; // black
        }
        $coordinates = $rgb->coordinates();

        return [
            'r' => $coordinates[0],
            'g' => $coordinates[1],
            'b' => $coordinates[2],
        ];
    }

    /** @return array<string,float> */
    public static function hexToHsl(string $hex): array
    {
        $hsl = ColorFactory::newHsl($hex, ColorSpace::HexRgb);
        if ($hsl === null) {
            return ['h' => 0, 's' => 0, 'l' => 0]; // black
        }
        $coordinates = array_map(fn ($c) => round($c, 3), $hsl->coordinates());

        return [
            'h' => $coordinates[0],
            's' => $coordinates[1],
            'l' => $coordinates[2],
        ];
    }

    /** @return array<string,mixed> */
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
