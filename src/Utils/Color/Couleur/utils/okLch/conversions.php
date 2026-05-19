<?php

namespace TotalCMS\Utils\Color\Couleur\utils\okLch;

use       TotalCMS\Utils\Color\Couleur\utils\hsl;
use       TotalCMS\Utils\Color\Couleur\utils\linRgb;
use       TotalCMS\Utils\Color\Couleur\utils\okLab;
use       TotalCMS\Utils\Color\Couleur\utils\rgb;
use       TotalCMS\Utils\Color\Couleur\utils\xyzD65;

function toHexRgb(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return rgb\toHexRgb(... toRgb($lightness, $chroma, $hue, $opacity));
}

function toHsl(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return rgb\toHsl(... toRgb($lightness, $chroma, $hue, $opacity));
}

function toLinRgb(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return xyzD65\toLinRgb(... toXyzD65($lightness, $chroma, $hue, $opacity));
}

function toOkLab(
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

function toRgb(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return linRgb\toRgb(... toLinRgb($lightness, $chroma, $hue, $opacity));
}

function toXyzD65(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return okLab\toXyzD65(... toOkLab($lightness, $chroma, $hue, $opacity));
}