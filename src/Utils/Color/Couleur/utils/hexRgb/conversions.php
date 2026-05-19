<?php

namespace TotalCMS\Utils\Color\Couleur\utils\hexRgb;

use       TotalCMS\Utils\Color\Couleur\utils;
use       TotalCMS\Utils\Color\Couleur\utils\hsl;
use       TotalCMS\Utils\Color\Couleur\utils\linRgb;
use       TotalCMS\Utils\Color\Couleur\utils\okLab;
use       TotalCMS\Utils\Color\Couleur\utils\rgb;
use       TotalCMS\Utils\Color\Couleur\utils\xyzD65;

function toHsl(
    string $red     = '00',
    string $green   = '00',
    string $blue    = '00',
    string $opacity = 'FF',
) :array {
    return rgb\toHsl(... toRgb($red, $green, $blue, $opacity));
}

function toLinRgb(
    string $red     = '00',
    string $green   = '00',
    string $blue    = '00',
    string $opacity = 'FF',
) :array {
    return rgb\toLinRgb(... toRgb($red, $green, $blue, $opacity));
}

function toOkLab(
    string $red     = '00',
    string $green   = '00',
    string $blue    = '00',
    string $opacity = 'FF',
) :array {
    return xyzD65\toOkLab(... toXyzD65($red, $green, $blue, $opacity));
}

function toOkLch(
    string $red     = '00',
    string $green   = '00',
    string $blue    = '00',
    string $opacity = 'FF',
) :array {
    return okLab\toOkLch(... toOkLab($red, $green, $blue, $opacity));
}

function toRgb(
    string $red     = '00',
    string $green   = '00',
    string $blue    = '00',
    string $opacity = 'FF',
) :array {
    return [
        utils\hexToDec($red),
        utils\hexToDec($green),
        utils\hexToDec($blue),
        utils\hexToDec($opacity),
    ];
}

function toXyzD65(
    string $red     = '00',
    string $green   = '00',
    string $blue    = '00',
    string $opacity = 'FF',
) :array {
    return linRgb\toXyzD65(... toLinRgb($red, $green, $blue, $opacity));
}