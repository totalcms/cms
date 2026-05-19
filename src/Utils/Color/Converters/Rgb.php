<?php

namespace TotalCMS\Utils\Color\Converters;

use       TotalCMS\Utils\Color\ColorSpace;
use       TotalCMS\Utils\Color\Constant;
use       TotalCMS\Utils\Color\Util;
use       TotalCMS\Utils\Color\Exceptions\MissingColorValue;

abstract class Rgb {

    public static function clean(
        mixed     $value,
        bool|null $throw = null,
    ) :array {
        $values  = Util::parseColorValue($value, 255);
        $red     = $values['red']     ?? $values['r'] ?? $values[0] ?? null;
        $green   = $values['green']   ?? $values['g'] ?? $values[1] ?? null;
        $blue    = $values['blue']    ?? $values['b'] ?? $values[2] ?? null;
        $opacity = $values['opacity'] ?? $values['o'] ?? $values[3] ?? null;

        return match (true) {
            !$throw           => null,
            ($red   === null) => throw new MissingColorValue('red'),
            ($green === null) => throw new MissingColorValue('green'),
            ($blue  === null) => throw new MissingColorValue('blue'),
            default           => null,
        } ?? [
            Util::cleanCoordinate($red     ?? 0,   0, 255),
            Util::cleanCoordinate($green   ?? 0,   0, 255),
            Util::cleanCoordinate($blue    ?? 0,   0, 255),
            Util::cleanCoordinate($opacity ?? 255, 0, 255),
        ];
    }

    public static function from(
        mixed                              $value,
        ColorSpace|\Stringable|string|null $from     = null,
        array|null                         $fallback = null,
        bool|null                          $throw    = null,
    ) :array {
        return Util::to(
            value    : $value,
            to       : ColorSpace::Rgb,
            from     : $from,
            fallback : $fallback,
            throw    : $throw,
        );
    }

    public static function stringify(
        float     $red,
        float     $green,
        float     $blue,
        float     $opacity   = 255,
        bool|null $legacy    = null,
        bool|null $alpha     = null,
        int|null  $precision = null,
    ) :string {
        $legacy    ??= Constant::LEGACY->value();
        $precision ??= Constant::PRECISION->value();
        $function    = 'rgb';
        $s1          = ' ';
        $s2          = ' / ';
        $unit        = '%';
        $aUnit       = '';
        $alpha     ??= ($opacity !== (float) 255);

        if ($legacy) {
            if ($alpha) {
                $function = 'rgba';
            }

            $opacity /= 255;
            $unit     = '';
            $s1       =
            $s2       = ',';
        }
        else {
            $red     /= 2.55;
            $green   /= 2.55;
            $blue    /= 2.55;
            $opacity /= 2.55;
            $aUnit    = '%';
        }

        $value = "$function("
            .\round($red, $precision)
            .$unit
            .$s1
            .\round($green, $precision)
            .$unit
            .$s1
            .\round($blue, $precision)
            .$unit
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
        return Util::isColorString($value, ColorSpace::Rgb)
            || Util::validateArray(
                value  : $value,
                filter : fn ($v) => !\is_object($v) && ((int) $v >= 0) && ((int) $v <= 255)
            )
        ;
    }

    public static function toHexRgb(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 255,
    ) :array {
        return [
            Util::decToHex(\min(255, $red)),
            Util::decToHex(\min(255, $green)),
            Util::decToHex(\min(255, $blue)),
            Util::decToHex(\min(255, $opacity)),
        ];
    }

    public static function toHsl(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 255,
    ) :array {
        $red      /= 255;
        $green    /= 255;
        $blue     /= 255;
        $max       = \max($red, $green, $blue);
        $min       = \min($red, $green, $blue);
        $lightness = ($max + $min) / 2;

        if ($max == $min) {
            $hue        =
            $saturation = 0;
        }
        else {
            $d          = $max - $min;
            $saturation = ($lightness > 0.5)
                ? $d / (2 - $max - $min)
                : $d / ($max + $min)
            ;

            $hue = 0.0;
            switch ($max) {
                case $red:
                    $hue = ($green - $blue) / $d + ($green < $blue ? 6 : 0);
                    break;
                case $green:
                    $hue = ($blue - $red) / $d + 2;
                    break;
                case $blue:
                    $hue = ($red - $green) / $d + 4;
                    break;
            }

            $hue /= 6;
        }

        return [
            (float) $hue        * 360,
            (float) $saturation * 100,
            (float) $lightness  * 100,
            (float) $opacity    / 2.55,
        ];
    }

    public static function toLinRgb(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 255,
    ) :array {
        return Util::push(
            value : (float) ($opacity / 255),
            array : \array_map(
                callback : function (int|float $v) {
                    $v    = (float) ($v / 255);
                    $abs  = \abs($v);
                    $sign = ($v < 0)
                        ? -1
                        : 1;

                    if ($abs < 0.04045) {
                        return $v / 12.92;
                    }

                    return (float) (
                        $sign * \pow(($abs + 0.055) / 1.055, 2.4)
                    );
                },
                array : [ $red, $green, $blue ],
            ),
        );
    }

    public static function toOkLab(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 255,
    ) :array {
        return XyzD65::toOkLab(... self::toXyzD65($red, $green, $blue, $opacity));
    }

    public static function toOkLch(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 255,
    ) :array {
        return OkLab::toOkLch(... self::toOkLab($red, $green, $blue, $opacity));
    }

    public static function toXyzD65(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 255,
    ) :array {
        return LinRgb::toXyzD65(... self::toLinRgb($red, $green, $blue, $opacity));
    }
}
