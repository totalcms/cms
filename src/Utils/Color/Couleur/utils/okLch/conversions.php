<?php

namespace TotalCMS\Utils\Color\Couleur\utils\okLch;

use       TotalCMS\Utils\Color\Couleur\CssColor;
use       TotalCMS\Utils\Color\Couleur\utils\hsl;
use       TotalCMS\Utils\Color\Couleur\utils\hsv;
use       TotalCMS\Utils\Color\Couleur\utils\lab;
use       TotalCMS\Utils\Color\Couleur\utils\linP3;
use       TotalCMS\Utils\Color\Couleur\utils\linProPhoto;
use       TotalCMS\Utils\Color\Couleur\utils\linRgb;
use       TotalCMS\Utils\Color\Couleur\utils\okLab;
use       TotalCMS\Utils\Color\Couleur\utils\rgb;
use       TotalCMS\Utils\Color\Couleur\utils\xyzD50;
use       TotalCMS\Utils\Color\Couleur\utils\xyzD65;

function toCss(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :CssColor {
    return rgb\toCss(... toRgb($lightness, $chroma, $hue, $opacity));
}

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

function toHsv(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return hsl\toHsv(... toHsl($lightness, $chroma, $hue, $opacity));
}

function toHwb(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return hsv\toHwb(... toHsv($lightness, $chroma, $hue, $opacity));
}

function toLab(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return xyzD50\toLab(... toXyzD50($lightness, $chroma, $hue, $opacity));
}

function toLch(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return lab\toLch(... toLab($lightness, $chroma, $hue, $opacity));
}

function toLinP3(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return xyzD65\toLinP3(... toXyzD65($lightness, $chroma, $hue, $opacity));
}

function toLinProPhoto(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return xyzD50\toLinProPhoto(... toXyzD50($lightness, $chroma, $hue, $opacity));
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

function toP3(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return linP3\toP3(... toLinP3($lightness, $chroma, $hue, $opacity));
}

function toProPhoto(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return linProPhoto\toProPhoto(... toLinProPhoto($lightness, $chroma, $hue, $opacity));
}

function toRgb(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return linRgb\toRgb(... toLinRgb($lightness, $chroma, $hue, $opacity));
}

function toXyzD50(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return xyzD65\toXyzD50(... toXyzD65($lightness, $chroma, $hue, $opacity));
}

function toXyzD65(
    float $lightness = 0,
    float $chroma    = 0,
    float $hue       = 0,
    float $opacity   = 100,
) :array {
    return okLab\toXyzD65(... toOkLab($lightness, $chroma, $hue, $opacity));
}