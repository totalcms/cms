<?php

namespace TotalCMS\Utils\Color\Couleur\Converters;

use       TotalCMS\Utils\Color\Couleur\ColorSpace;
use       TotalCMS\Utils\Color\Couleur\Constant;
use       TotalCMS\Utils\Color\Couleur\Util;
use       TotalCMS\Utils\Color\Couleur\Exceptions\MissingColorValue;

abstract class OkLab {

    public static function clean(
        mixed     $value,
        bool|null $throw = null,
    ) :array {
        $values    = Util::parseColorValue($value, 100);
        $lightness = $values['lightness'] ?? $values['l'] ?? $values[0] ?? null;
        $a         =                         $values['a'] ?? $values[1] ?? null;
        $b         =                         $values['b'] ?? $values[2] ?? null;
        $opacity   = $values['opacity']   ?? $values['o'] ?? $values[3] ?? null;

        return match (true) {
            !$throw               => null,
            ($lightness === null) => throw new MissingColorValue('lightness'),
            ($a         === null) => throw new MissingColorValue('a'),
            ($b         === null) => throw new MissingColorValue('b'),
            default               => null,
        } ?? [
            Util::cleanCoordinate($lightness ?? 0,   0,    100),
            Util::cleanCoordinate($a         ?? 0,   null, null),
            Util::cleanCoordinate($b         ?? 0,   null, null),
            Util::cleanCoordinate($opacity   ?? 100, 0,    100),
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
            to       : ColorSpace::OkLab,
            from     : $from,
            fallback : $fallback,
            throw    : $throw,
        );
    }

    public static function stringify(
        float     $lightness,
        float     $a,
        float     $b,
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
        $abUnit      = '';
        $aUnit       = '%';
        $alpha     ??= ($opacity !== (float) 100);

        if ($legacy) {
            $opacity /= 100;
            $aUnit    = '';
            $s1       =
            $s2       = ',';
        }

        $value = "oklab("
            .\round($lightness, $precision)
            .$lUnit
            .$s1
            .\round($a, $precision)
            .$abUnit
            .$s1
            .\round($b, $precision)
            .$abUnit
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
        return Util::isColorString($value, ColorSpace::OkLab);
    }

    public static function toHexRgb(
        float $lightness = 0,
        float $a         = 0,
        float $b         = 0,
        float $opacity   = 100,
    ) :array {
        return Rgb::toHexRgb(... self::toRgb($lightness, $a, $b, $opacity));
    }

    public static function toHsl(
        float $lightness = 0,
        float $a         = 0,
        float $b         = 0,
        float $opacity   = 100,
    ) :array {
        return Rgb::toHsl(... self::toRgb($lightness, $a, $b, $opacity));
    }

    public static function toLinRgb(
        float $lightness = 0,
        float $a         = 0,
        float $b         = 0,
        float $opacity   = 100,
    ) :array {
        return XyzD65::toLinRgb(... self::toXyzD65($lightness, $a, $b, $opacity));
    }

    public static function toOkLch(
        float $lightness = 0,
        float $a         = 0,
        float $b         = 0,
        float $opacity   = 100,
    ) :array {
        $hue = \atan2($b, $a) * 180 / \pi();

        return [
            $lightness,
            \sqrt($a ** 2 + $b ** 2),
            ($hue >= 0)
                ? $hue
                : $hue + 360,
            $opacity,
        ];
    }

    public static function toRgb(
        float $lightness = 0,
        float $a         = 0,
        float $b         = 0,
        float $opacity   = 100,
    ) :array {
        return LinRgb::toRgb(... self::toLinRgb($lightness, $a, $b, $opacity));
    }

    public static function toXyzD65(
        float $lightness = 0,
        float $a         = 0,
        float $b         = 0,
        float $opacity   = 100,
    ) :array {
        // Divide $lightness by 100 to convert from CSS OkLab:
        $lightness /= 100;

        return Util::push(
            value : $opacity / 100,
            array : Util::multiplyMatrices(
                a : [
                        [  1.2268798733741557,  -0.5578149965554813,  0.28139105017721583 ],
                        [ -0.04057576262431372,  1.1122868293970594, -0.07171106666151701 ],
                        [ -0.07637294974672142, -0.4214933239627914,  1.5869240244272418  ],
                ],
                b : \array_map(
                    callback : fn ($v) => $v ** 3,
                    array    : Util::multiplyMatrices(
                        a : [
                                [ 0.99999999845051981432,  0.39633779217376785678,   0.21580375806075880339  ],
                                [ 1.0000000088817607767,  -0.1055613423236563494,   -0.063854174771705903402 ],
                                [ 1.0000000546724109177,  -0.089484182094965759684, -1.2914855378640917399   ],
                        ],
                        b : [ $lightness, $a, $b ],
                    ),
                ),
            ),
        );
    }
}
