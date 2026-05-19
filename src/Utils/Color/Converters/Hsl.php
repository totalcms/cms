<?php

namespace TotalCMS\Utils\Color\Converters;

use       TotalCMS\Utils\Color\ColorSpace;
use       TotalCMS\Utils\Color\Constant;
use       TotalCMS\Utils\Color\Util;
use       TotalCMS\Utils\Color\Exceptions\MissingColorValue;

abstract class Hsl {

    /**
     * @return array<int, float|int>
     */
    public static function clean(
        mixed     $value,
        bool|null $throw = null,
    ) :array {
        $values     = Util::parseColorValue($value, 100);
        $hue        = $values['hue']        ?? $values['h'] ?? $values[0] ?? null;
        $saturation = $values['saturation'] ?? $values['s'] ?? $values[1] ?? null;
        $lightness  = $values['lightness']  ?? $values['l'] ?? $values[2] ?? null;
        $opacity    = $values['opacity']    ?? $values['o'] ?? $values[3] ?? null;

        // @phpstan-ignore-next-line nullCoalesce.expr
        return match (true) {
            !$throw                => null,
            ($hue        === null) => throw new MissingColorValue('hue'),
            ($saturation === null) => throw new MissingColorValue('saturation'),
            ($lightness  === null) => throw new MissingColorValue('lightness'),
            default                => null,
        } ?? [
            Util::cleanCoordinate($hue        ?? 0,   0, 360, true),
            Util::cleanCoordinate($saturation ?? 0,   0, 100, false),
            Util::cleanCoordinate($lightness  ?? 0,   0, 100, false),
            Util::cleanCoordinate($opacity    ?? 100, 0, 100, false),
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
            to       : ColorSpace::Hsl,
            from     : $from,
            fallback : $fallback,
            throw    : $throw,
        );
        return $result;
    }

    public static function stringify(
        float     $hue,
        float     $saturation,
        float     $lightness,
        float     $opacity   = 100,
        bool|null $legacy    = null,
        bool|null $alpha     = null,
        int|null  $precision = null,
    ) :string {
        $legacy    ??= Constant::LEGACY->value();
        $precision ??= Constant::PRECISION->value();
        $function    = 'hsl';
        $s1          = ' ';
        $s2          = ' / ';
        $hUnit       = 'deg';
        $slUnit      = '%';
        $aUnit       = '%';
        $alpha     ??= ($opacity !== (float) 100);

        if ($legacy) {
            if ($alpha) {
                $function = 'hsla';
            }

            $opacity /= 100;
            $aUnit    = '';
            $s1       =
            $s2       = ',';
        }

        $value = "$function("
            .\round($hue, $precision)
            .$hUnit
            .$s1
            .\round($saturation, $precision)
            .$slUnit
            .$s1
            .\round($lightness, $precision)
            .$slUnit
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
        return Util::isColorString($value, ColorSpace::Hsl);
    }

    /**
     * @return array<int, string>
     */
    public static function toHexRgb(
        float $hue        = 0,
        float $saturation = 0,
        float $lightness  = 0,
        float $opacity    = 100,
    ) :array {
        return Rgb::toHexRgb(... self::toRgb($hue, $saturation, $lightness, $opacity));
    }

    /**
     * @return array<int, float|int>
     */
    public static function toLinRgb(
        float $hue        = 0,
        float $saturation = 0,
        float $lightness  = 0,
        float $opacity    = 100,
    ) :array {
        return Rgb::toLinRgb(... self::toRgb($hue, $saturation, $lightness, $opacity));
    }

    /**
     * @return array<int, float|int>
     */
    public static function toOkLab(
        float $hue        = 0,
        float $saturation = 0,
        float $lightness  = 0,
        float $opacity    = 100,
    ) :array {
        return XyzD65::toOkLab(... self::toXyzD65($hue, $saturation, $lightness, $opacity));
    }

    /**
     * @return array<int, float|int>
     */
    public static function toOkLch(
        float $hue        = 0,
        float $saturation = 0,
        float $lightness  = 0,
        float $opacity    = 100,
    ) :array {
        return OkLab::toOkLch(... self::toOkLab($hue, $saturation, $lightness, $opacity));
    }

    /**
     * @return array<int, float|int>
     */
    public static function toRgb(
        float    $hue        = 0,
        float    $saturation = 0,
        float    $lightness  = 0,
        float    $opacity    = 100,
        int|null $precision  = null,
    ) :array {
        $precision ??= 0;
        $hue        /= 60;

        if ($hue < 0) {
            $hue = 6 - \fmod(-$hue, 6);
        }

        $hue        = \fmod($hue, 6);
        $saturation = \max(0, \min(1, $saturation / 100));
        $lightness  = \max(0, \min(1, $lightness / 100));
        $c          = (1 - \abs((2 * $lightness) - 1)) * $saturation;
        $x          = $c * (1 - \abs(\fmod($hue, 2) - 1));

        if ($hue < 1) {
            $red   = $c;
            $green = $x;
            $blue  = 0;
        }
        else if ($hue < 2) {
            $red   = $x;
            $green = $c;
            $blue  = 0;
        }
        else if ($hue < 3) {
            $red   = 0;
            $green = $c;
            $blue  = $x;
        }
        else if ($hue < 4) {
            $red   = 0;
            $green = $x;
            $blue  = $c;
        }
        else if ($hue < 5) {
            $red   = $x;
            $green = 0;
            $blue  = $c;
        }
        else {
            $red   = $c;
            $green = 0;
            $blue  = $x;
        }

        $m       = $lightness - $c / 2;
        $red     = \round(($red   + $m) * 255, $precision);
        $green   = \round(($green + $m) * 255, $precision);
        $blue    = \round(($blue  + $m) * 255, $precision);
        $opacity = \round($opacity * 2.55, $precision);

        return [
            (float) $red,
            (float) $green,
            (float) $blue,
            (float) $opacity,
        ];
    }

    /**
     * @return array<int, float|int>
     */
    public static function toXyzD65(
        float $hue        = 0,
        float $saturation = 0,
        float $lightness  = 0,
        float $opacity    = 100,
    ) :array {
        return LinRgb::toXyzD65(... self::toLinRgb($hue, $saturation, $lightness, $opacity));
    }
}
