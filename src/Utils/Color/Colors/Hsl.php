<?php

namespace TotalCMS\Utils\Color\Colors;

use       TotalCMS\Utils\Color\Color;
use       TotalCMS\Utils\Color\ColorFactory;
use       TotalCMS\Utils\Color\ColorInterface;
use       TotalCMS\Utils\Color\Util;
use       TotalCMS\Utils\Color\Converters\Hsl as HslConverter;

class      Hsl
extends    Color
implements ColorInterface {

    /* #region Constructor */

    public function __construct(
        public readonly float $hue        = 0,
        public readonly float $saturation = 0,
        public readonly float $lightness  = 0,
        public readonly float $opacity    = 100,
    ) {

    }

    /* #endregion */

    /* #region Public Static Methods */
    
    public static function aliases(

    ) :array {
        return [
            'hsl',
            'hsla',
        ];
    }

    /* #endregion */
    
    /* #region Public Methods */

    public function change(
        \Stringable|string|int|float|null $hue        = null,
        \Stringable|string|int|float|null $saturation = null,
        \Stringable|string|int|float|null $lightness  = null,
        \Stringable|string|int|float|null $opacity    = null,
        Hsl|null                          $fallback   = null,
        bool|null                         $throw      = null,
    ) :Hsl {
        $changeThrow = $throw ?? true;

        return ColorFactory::newHsl(
            value    : [
                Util::changeCoordinate($this->hue,        $hue,        false, $changeThrow, true),
                Util::changeCoordinate($this->saturation, $saturation, false, $changeThrow),
                Util::changeCoordinate($this->lightness,  $lightness,  false, $changeThrow),
                Util::changeCoordinate($this->opacity,    $opacity,    false, $changeThrow),
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
        return HslConverter::stringify(
            hue        : $this->hue,
            saturation : $this->saturation,
            lightness  : $this->lightness,
            opacity    : $this->opacity,
            legacy     : $legacy,
            alpha      : $alpha,
            precision  : $precision,
        );
    }

    /* #endregion */
}