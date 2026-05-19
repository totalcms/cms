<?php

namespace TotalCMS\Utils\Color\Couleur\utils\xyzD65;

use       TotalCMS\Utils\Color\Couleur\utils;
use       TotalCMS\Utils\Color\Couleur\utils\hsl;
use       TotalCMS\Utils\Color\Couleur\utils\linRgb;
use       TotalCMS\Utils\Color\Couleur\utils\okLab;
use       TotalCMS\Utils\Color\Couleur\utils\rgb;

function toHexRgb(
    float $x       = 0,
    float $y       = 0,
    float $z       = 0,
    float $opacity = 1,
) :array {
    return rgb\toHexRgb(... toRgb($x, $y, $z, $opacity));
}

function toHsl(
    float    $x       = 0,
    float    $y       = 0,
    float    $z       = 0,
    float    $opacity = 1,
) :array {
    return rgb\toHsl(... toRgb($x, $y, $z, $opacity));
}

function toLinRgb(
    float $x       = 0,
    float $y       = 0,
    float $z       = 0,
    float $opacity = 1,
) :array {
    return utils\push(
        value : $opacity,
        array : utils\multiplyMatrices(
            a : [
                [  3.2409699419045226,  -1.537383177570094,   -0.4986107602930034  ],
                [ -0.9692436362808796,   1.8759675015077202,   0.04155505740717559 ],
                [  0.05563007969699366, -0.20397695888897652,  1.0569715142428786  ],
            ],
            b : [ $x, $y, $z ],
        ),
    );
}

function toOkLab(
    float $x       = 0,
    float $y       = 0,
    float $z       = 0,
    float $opacity = 1,
) :array {
    $okLab = utils\push(
        value : $opacity * 100,
        array : utils\multiplyMatrices(
            a : [
                [  0.2104542553,  0.7936177850, -0.0040720468 ],
                [  1.9779984951, -2.4285922050,  0.4505937099 ],
                [  0.0259040371,  0.7827717662, -0.8086757660 ],
            ],
            b : \array_map(
                callback : fn ($v) => \pow($v, 1/3),
                array    : utils\multiplyMatrices(
                    a : [
                        [ 0.8190224432164319,   0.3619062562801221,  -0.12887378261216414 ],
                        [ 0.0329836671980271,   0.9292868468965546,   0.03614466816999844 ],
                        [ 0.048177199566046255, 0.26423952494422764,  0.6335478258136937  ],
                    ],
                    b : [ $x, $y, $z ],
                ),
            ),
        ),
    );

    // Multiply Lightness by 100 so it is compatible with CSS OkLab:
    $okLab[0] *= 100;

    return $okLab;
}

function toOkLch(
    float $x       = 0,
    float $y       = 0,
    float $z       = 0,
    float $opacity = 1,
) :array {
    return okLab\toOkLch(... toOkLab($x, $y, $z, $opacity));
}

function toRgb(
    float $x       = 0,
    float $y       = 0,
    float $z       = 0,
    float $opacity = 1,
) :array {
    return linRgb\toRgb(... toLinRgb($x, $y, $z, $opacity));
}

