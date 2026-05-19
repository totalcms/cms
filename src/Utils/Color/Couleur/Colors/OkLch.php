<?php

namespace TotalCMS\Utils\Color\Couleur\Colors;

use       TotalCMS\Utils\Color\Couleur\Color;
use       TotalCMS\Utils\Color\Couleur\ColorFactory;
use       TotalCMS\Utils\Color\Couleur\ColorInterface;
use       TotalCMS\Utils\Color\Couleur\Util;
use       TotalCMS\Utils\Color\Couleur\Converters\OkLch as OkLchConverter;

class      OkLch
extends    Color
implements ColorInterface {

    /* #region Constructor */

    public function __construct(
        public readonly float $lightness = 0,
        public readonly float $chroma    = 0,
        public readonly float $hue       = 0,
        public readonly float $opacity   = 100,
    ) {

    }

    /* #endregion */

    /* #region Public Static Methods */
    
    public static function aliases(

    ) :array {
        return [
            'oklch',
            'ok-lch',
            'ok_lch',
        ];
    }

    /* #endregion */
    
    /* #region Public Methods */

    public function change(
        \Stringable|string|int|float|null $lightness = null,
        \Stringable|string|int|float|null $chroma    = null,
        \Stringable|string|int|float|null $hue       = null,
        \Stringable|string|int|float|null $opacity   = null,
        OkLch|null                        $fallback  = null,
        bool|null                         $throw     = null,
    ) :OkLch {
        $changeThrow = $throw ?? true;

        return ColorFactory::newOkLch(
            value    : [
                Util::changeCoordinate($this->lightness, $lightness, false, $changeThrow),
                Util::changeCoordinate($this->chroma,    $chroma,    false, $changeThrow),
                Util::changeCoordinate($this->hue,       $hue,       false, $changeThrow, true),
                Util::changeCoordinate($this->opacity,   $opacity,   false, $changeThrow),
            ],
            from     : $this::space(),
            fallback : $fallback,
            throw    : $throw,
        );
    } 
    
    public function stringify(
        bool|null $legacy    = null,
        bool|null $alpha     = null,
        int|null  $precision = null,
    ) :string {
        return OkLchConverter::stringify(
            lightness : $this->lightness,
            chroma    : $this->chroma,
            hue       : $this->hue,
            opacity   : $this->opacity,
            legacy    : $legacy,
            alpha     : $alpha,
            precision : $precision,
        );
    }

    /* #endregion */

}