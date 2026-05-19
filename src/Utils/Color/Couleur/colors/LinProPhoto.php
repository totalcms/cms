<?php

namespace TotalCMS\Utils\Color\Couleur\colors;

use       TotalCMS\Utils\Color\Couleur\Color;
use       TotalCMS\Utils\Color\Couleur\ColorFactory;
use       TotalCMS\Utils\Color\Couleur\ColorInterface;
use       TotalCMS\Utils\Color\Couleur\utils;

class      LinProPhoto
extends    Color
implements ColorInterface {

    /* #region Constructor */

    public function __construct(
        public readonly float $red     = 0,
        public readonly float $green   = 0,
        public readonly float $blue    = 0,
        public readonly float $opacity = 1,
    ) {

    }

    /* #endregion */

    /* #region Public Static Methods */
    
    public static function aliases(

    ) :array {
        return [
            'prophoto-linear',
            'prophoto_linear',
            'linprophoto',
            'lin-prophoto',
            'lin_prophoto',
        ];
    }

    /* #endregion */
    
    /* #region Public Methods */

    public function change(
        \Stringable|string|int|float|null $red       = null,
        \Stringable|string|int|float|null $green     = null,
        \Stringable|string|int|float|null $blue      = null,
        \Stringable|string|int|float|null $opacity   = null,
        LinP3|null                        $fallback  = null,
        bool|null                         $throw     = null,
    ) :LinProPhoto {
        $changeThrow = $throw ?? true;

        return ColorFactory::newLinProPhoto(
            value    : [
                utils\changeCoordinate($this->red,     $red,     false, $changeThrow),
                utils\changeCoordinate($this->green,   $green,   false, $changeThrow),
                utils\changeCoordinate($this->blue,    $blue,    false, $changeThrow),
                utils\changeCoordinate($this->opacity, $opacity, false, $changeThrow),
            ],
            from     : $this::space(),
            fallback : $fallback,
            throw    : $throw,
        );
    } 
    
    public function stringify(
        bool|null $alpha     = null,
        int|null  $precision = null,
    ) :string {
        return utils\linProPhoto\stringify(
            red       : $this->red,
            green     : $this->green,
            blue      : $this->blue,
            opacity   : $this->opacity,
            alpha     : $alpha,
            precision : $precision,
        );
    }

    /* #endregion */

}