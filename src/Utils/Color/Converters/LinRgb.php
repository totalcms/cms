<?php

namespace TotalCMS\Utils\Color\Converters;

use       TotalCMS\Utils\Color\ColorSpace;
use       TotalCMS\Utils\Color\Constant;
use       TotalCMS\Utils\Color\Util;
use       TotalCMS\Utils\Color\Exceptions\MissingColorValue;

abstract class LinRgb {

    public static function clean(
        mixed     $value,
        bool|null $throw = null,
    ) :array {
        $values  = Util::parseColorValue($value, 1);
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
            Util::cleanCoordinate($red     ?? 0, 0, 1),
            Util::cleanCoordinate($green   ?? 0, 0, 1),
            Util::cleanCoordinate($blue    ?? 0, 0, 1),
            Util::cleanCoordinate($opacity ?? 1, 0, 1),
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
            to       : ColorSpace::LinRgb,
            from     : $from,
            fallback : $fallback,
            throw    : $throw,
        );
    }

    public static function stringify(
        float     $red,
        float     $green,
        float     $blue,
        float     $opacity   = 1,
        bool|null $alpha     = null,
        int|null  $precision = null,
    ) :string {
        $precision ??= Constant::PRECISION->value();
        $alpha     ??= ($opacity !== (float) 1);

        $value = "color(srgb-linear "
            .\round($red, $precision)
            .' '
            .\round($green, $precision)
            .' '
            .\round($blue, $precision)
        ;

        if (!$alpha) {
            return "$value)";
        }

        return $value
            .' / '
            .$opacity * 100
            .'%)'
        ;
    }

    public static function verify(
        mixed $value,
    ) :bool {
        return Util::isColorString($value, ColorSpace::LinRgb);
    }

    public static function toHexRgb(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 1,
    ) :array {
        return Rgb::toHexRgb(... self::toRgb($red, $green, $blue, $opacity));
    }

    public static function toHsl(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 1,
    ) :array {
        return Rgb::toHsl(... self::toRgb($red, $green, $blue, $opacity));
    }

    public static function toOkLab(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 1,
    ) :array {
        return XyzD65::toOkLab(... self::toXyzD65($red, $green, $blue, $opacity));
    }

    public static function toOkLch(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 1,
    ) :array {
        return OkLab::toOkLch(... self::toOkLab($red, $green, $blue, $opacity));
    }

    public static function toRgb(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 1,
    ) :array {
        return Util::push(
            value : $opacity * 255,
            array : \array_map(
                callback : function (int|float $v) {
                    $abs  = \abs($v);
                    $sign = ($v < 0)
                        ? -1
                        : 1
                    ;

                    return 255 * (($abs > 0.0031308)
                        ? $sign * (1.055 * \pow($abs, 1/2.4) - 0.055)
                        : 12.92 * $v
                    );
                },
                array : [ $red, $green, $blue ],
            ),
        );
    }

    public static function toXyzD65(
        float $red     = 0,
        float $green   = 0,
        float $blue    = 0,
        float $opacity = 1,
    ) :array {
        return Util::push(
            value : $opacity,
            array : Util::multiplyMatrices(
                a : [
                    [ 0.41239079926595934, 0.357584339383878,   0.1804807884018343  ],
                    [ 0.21263900587151027, 0.715168678767756,   0.07219231536073371 ],
                    [ 0.01933081871559182, 0.11919477979462598, 0.9505321522496607  ],
                ],
                b : [
                    $red,
                    $green,
                    $blue,
                ],
            ),
        );
    }
}
