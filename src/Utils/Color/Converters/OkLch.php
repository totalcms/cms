<?php

namespace TotalCMS\Utils\Color\Converters;

use       TotalCMS\Utils\Color\ColorFactory;
use       TotalCMS\Utils\Color\ColorSpace;
use       TotalCMS\Utils\Color\Constant;
use       TotalCMS\Utils\Color\Util;
use       TotalCMS\Utils\Color\Exceptions\MissingColorValue;

abstract class OkLch {

    /**
     * @return array<int, float|int>
     */
    public static function clean(
        mixed     $value,
        bool|null $throw = null,
    ) :array {
        $values    = Util::parseColorValue($value, 100);
        $lightness = $values['lightness'] ?? $values['l'] ?? $values[0] ?? null;
        $chroma    = $values['chroma']    ?? $values['c'] ?? $values[1] ?? null;
        $hue       = $values['hue']       ?? $values['h'] ?? $values[2] ?? null;
        $opacity   = $values['opacity']   ?? $values['o'] ?? $values[3] ?? null;

        // @phpstan-ignore-next-line nullCoalesce.expr
        return match (true) {
            !$throw               => null,
            ($lightness === null) => throw new MissingColorValue('lightness'),
            ($chroma    === null) => throw new MissingColorValue('chroma'),
            ($hue       === null) => throw new MissingColorValue('hue'),
            default               => null,
        } ?? [
            Util::cleanCoordinate($lightness ?? 0,   0,    100,  false),
            Util::cleanCoordinate($chroma    ?? 0,   null, null, false),
            Util::cleanCoordinate($hue       ?? 0,   0,    360,  true),
            Util::cleanCoordinate($opacity   ?? 100, 0,    100,  false),
        ];
    }

    /**
     * @param  array<int, float|int>|null $fallback
     *
     * @return array<int, float|int>|null
     */
    public static function from(
        mixed                              $value,
        ColorSpace|\Stringable|string|null $from     = null,
        array|null                         $fallback = null,
        bool|null                          $throw    = null,
    ) :array|null {
        /** @var array<int, float|int>|null $result */
        $result = Util::to(
            value    : $value,
            to       : ColorSpace::OkLch,
            from     : $from,
            fallback : $fallback,
            throw    : $throw,
        );
        return $result;
    }

    public static function stringify(
        float     $lightness,
        float     $chroma,
        float     $hue,
        float     $opacity   = 100,
        bool|null $legacy    = null,
        bool|null $alpha     = null,
        int|null  $precision = null,
    ) :string {
        $legacy    ??= Constant::LEGACY->value();
        $precision ??= Constant::PRECISION->value();
        $s1          = ' ';
        $s2          = ' / ';
        $lUnit       = '%';
        $cUnit       = '';
        $hUnit       = 'deg';
        $aUnit       = '%';
        $alpha     ??= ($opacity !== (float) 100);

        if ($legacy) {
            $opacity /= 100;
            $aUnit    = '';
            $s1       =
            $s2       = ',';
        }

        $value = "oklch("
            .\round($lightness, $precision)
            .$lUnit
            .$s1
            .\round($chroma, $precision)
            .$cUnit
            .$s1
            .\round($hue, $precision)
            .$hUnit
        ;

        if (!$alpha) {
            return "$value)";
        }

        return $value
            .$s2
            .$opacity
            .$aUnit
            .')'
        ;
    }

    public static function verify(
        mixed $value,
    ) :bool {
        return Util::isColorString($value, ColorSpace::OkLch);
    }

    /**
     * @return array<int, string>
     */
    public static function toHexRgb(
        float $lightness = 0,
        float $chroma    = 0,
        float $hue       = 0,
        float $opacity   = 100,
    ) :array {
        return Rgb::toHexRgb(... self::toRgb($lightness, $chroma, $hue, $opacity));
    }

    /**
     * @return array<int, float|int>
     */
    public static function toHsl(
        float $lightness = 0,
        float $chroma    = 0,
        float $hue       = 0,
        float $opacity   = 100,
    ) :array {
        return Rgb::toHsl(... self::toRgb($lightness, $chroma, $hue, $opacity));
    }

    /**
     * @return array<int, float|int>
     */
    public static function toLinRgb(
        float $lightness = 0,
        float $chroma    = 0,
        float $hue       = 0,
        float $opacity   = 100,
    ) :array {
        return XyzD65::toLinRgb(... self::toXyzD65($lightness, $chroma, $hue, $opacity));
    }

    /**
     * @return array<int, float|int>
     */
    public static function toOkLab(
        float $lightness = 0,
        float $chroma    = 0,
        float $hue       = 0,
        float $opacity   = 100,
    ) :array {
        return [
            $lightness,
            $chroma * \cos($hue * \pi() / 180),
            $chroma * \sin($hue * \pi() / 180),
            $opacity,
        ];
    }

    /**
     * @return array<int, float|int>
     */
    public static function toRgb(
        float $lightness = 0,
        float $chroma    = 0,
        float $hue       = 0,
        float $opacity   = 100,
    ) :array {
        return LinRgb::toRgb(... self::toLinRgb($lightness, $chroma, $hue, $opacity));
    }

    /**
     * @return array<int, float|int>
     */
    public static function toXyzD65(
        float $lightness = 0,
        float $chroma    = 0,
        float $hue       = 0,
        float $opacity   = 100,
    ) :array {
        return OkLab::toXyzD65(... self::toOkLab($lightness, $chroma, $hue, $opacity));
    }

    /**
     * Enhanced OKLCH utilities with improved hex conversion and hue handling.
     * These functions address ColorFactory issues identified in production use.
     */

    /**
     * Convert OKLCH coordinates to hex with enhanced error handling and boundary checking.
     * This function avoids ColorFactory stringify issues by manually formatting the hex output.
     *
     * @param array<string,float> $oklch OKLCH coordinates as ['l' => lightness, 'c' => chroma, 'h' => hue]
     * @return string Hex color string (e.g., '#ff0000')
     */
    public static function oklchToHex(array $oklch): string
    {
        // Convert OKLCH to RGB first to avoid ColorFactory hex issues
        $rgb = ColorFactory::newRgb([$oklch['l'], $oklch['c'], $oklch['h']], ColorSpace::OkLch);
        if ($rgb === null) {
            return '#000000'; // black fallback
        }

        $coordinates = $rgb->coordinates();

        // Manually format hex to avoid ColorFactory stringify issues
        // Apply proper boundary checking and rounding
        $r = max(0, min(255, round((float) $coordinates[0])));
        $g = max(0, min(255, round((float) $coordinates[1])));
        $b = max(0, min(255, round((float) $coordinates[2])));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Change OKLCH color with enhanced hue wraparound handling.
     * The ColorFactory library doesn't handle hue wrapping properly for 360° operations.
     *
     * @param array<string,float> $oklch Original OKLCH coordinates
     * @param array<string,mixed> $change Changes to apply (e.g., ['h' => '+10', 'l' => 0.1])
     * @return array<string,float> Modified OKLCH coordinates
     */
    public static function oklchChange(array $oklch, array $change): array
    {
        $oklchColor = ColorFactory::newOkLch([$oklch['l'], $oklch['c'], $oklch['h']], ColorSpace::OkLch);
        if ($oklchColor === null) {
            return ['l' => 0, 'c' => 0, 'h' => 0]; // black fallback
        }

        // ColorFactory library doesn't handle hue wrapping - only change hue if specified
        $updatedHue = $oklch['h'];
        if (isset($change['h'])) {
            $updatedHue = self::changeHue($oklch['h'], $change['h']);
        }

        $oklchColor = $oklchColor->change(
            lightness: $change['l'] ?? null,
            chroma: $change['c'] ?? null,
            hue: null // Don't let ColorFactory handle hue changes
        );

        $coordinates = array_map(fn ($c) => round((float) $c, 3), $oklchColor->coordinates());

        return [
            'l' => $coordinates[0],
            'c' => $coordinates[1],
            'h' => $updatedHue,
        ];
    }

    /**
     * Apply hue changes with proper 360° wraparound handling.
     * Supports formula-based changes like "+10", "-20", "*2", "/3".
     *
     * @param int|float $hue Current hue value (0-360)
     * @param string $formula Change formula (e.g., "+10", "-20", "*2", "/3")
     * @return float Updated hue value with proper wraparound
     */
    public static function changeHue(int|float $hue, string $formula): float
    {
        $formula = trim($formula);
        $operation = substr($formula, 0, 1);
        $value = floatval(substr($formula, 1));

        $hue = match ($operation) {
            '+' => $hue + $value,
            '-' => $hue - $value,
            '*' => $hue * $value,
            '/' => $value > 0 ? $hue / $value : $hue,
            default => $hue,
        };

        // Apply proper 360° wraparound for hue values
        if ($hue < 0 || $hue >= 360) {
            $hue = fmod($hue, 360);
            if ($hue < 0) {
                $hue += 360;
            }
        }

        return round($hue, 3);
    }

    /**
     * Enhanced OKLCH cleaning with better boundary checking.
     * This extends the base clean function with improved coordinate validation.
     *
     * @param mixed $value Input color value
     * @param bool|null $throw Whether to throw exceptions on error
     * @return array<int, float|int> Cleaned OKLCH coordinates
     */
    public static function cleanEnhanced(mixed $value, bool|null $throw = null): array
    {
        $cleaned = self::clean($value, $throw);

        // Additional boundary checking for better color accuracy
        // Ensure lightness is properly bounded (0-100%)
        $cleaned[0] = max(0, min(100, (float) $cleaned[0]));

        // Ensure chroma is non-negative (can be > 1 in OKLCH)
        $cleaned[1] = max(0, (float) $cleaned[1]);

        // Ensure hue is properly wrapped (0-360°)
        $cleaned[2] = fmod((float) $cleaned[2], 360);
        if ($cleaned[2] < 0) {
            $cleaned[2] += 360;
        }

        // Ensure opacity is properly bounded (0-100%)
        $cleaned[3] = max(0, min(100, (float) $cleaned[3]));

        /** @var array<int, float|int> $cleaned */
        return $cleaned;
    }
}
