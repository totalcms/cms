<?php

namespace App\Utils;

use App\Domain\Property\Data\ColorData;

/**
 * Color Utilities.
 */
class ColorUtils
{
    public static function colorToRGB(ColorData $hsla): array
    {
        // TODO: Implement colorToRGB() method.
        $rgba = [
            'r' => 0,
            'g' => 0,
            'b' => 0,
            'a' => 1,
        ];

        return $rgba;
    }

    public static function colorToHex(ColorData $hsla): string
    {
        // TODO: Implement colorToHex() method.
        $hex = '#000000';

        return $hex;
    }
}
