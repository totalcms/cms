<?php

namespace TotalCMS\Utils\Color\Couleur\utils\okLab;

use       TotalCMS\Utils\Color\Couleur\utils;
use       TotalCMS\Utils\Color\Couleur\utils\hsl;
use       TotalCMS\Utils\Color\Couleur\utils\linRgb;
use       TotalCMS\Utils\Color\Couleur\utils\rgb;
use       TotalCMS\Utils\Color\Couleur\utils\xyzD65;

function toHexRgb(
    float $lightness = 0,
    float $a         = 0,
    float $b         = 0,
    float $opacity   = 100,
) :array {
    return rgb\toHexRgb(... toRgb($lightness, $a, $b, $opacity));
}

function toHsl(
    float $lightness = 0,
    float $a         = 0,
    float $b         = 0,
    float $opacity   = 100,
) :array {
    return rgb\toHsl(... toRgb($lightness, $a, $b, $opacity));
}

function toLinRgb(
    float $lightness = 0,
    float $a         = 0,
    float $b         = 0,
    float $opacity   = 100,
) :array {
    return xyzD65\toLinRgb(... toXyzD65($lightness, $a, $b, $opacity));
}

function toOkLch(
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

function toRgb(
    float $lightness = 0,
    float $a         = 0,
    float $b         = 0,
    float $opacity   = 100,
) :array {
    return linRgb\toRgb(... toLinRgb($lightness, $a, $b, $opacity));
}

function toXyzD65(
    float $lightness = 0,
    float $a         = 0,
    float $b         = 0,
    float $opacity   = 100,
) :array {
    // Divide $lightness by 100 to convert from CSS OkLab:
    $lightness /= 100;

    return utils\push(
        value : $opacity / 100,
        array : utils\multiplyMatrices(
            a : [
                	[  1.2268798733741557,  -0.5578149965554813,  0.28139105017721583 ],
                	[ -0.04057576262431372,  1.1122868293970594, -0.07171106666151701 ],
                	[ -0.07637294974672142, -0.4214933239627914,  1.5869240244272418  ],
            ],
            b : \array_map(
                callback : fn ($v) => $v ** 3,
                array    : utils\multiplyMatrices(
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