<?php

namespace TotalCMS\Utils\Color\Colors;

use       TotalCMS\Utils\Color\Color;
use       TotalCMS\Utils\Color\ColorFactory;
use       TotalCMS\Utils\Color\ColorInterface;
use       TotalCMS\Utils\Color\Util;
use       TotalCMS\Utils\Color\Converters\Rgb as RgbConverter;

class      Rgb
extends    Color
implements ColorInterface {

    /* #region Constructor */

    public function __construct(
        public readonly float $red     = 0,
        public readonly float $green   = 0,
        public readonly float $blue    = 0,
        public readonly float $opacity = 255,
    ) {

    }

    /* #endregion */
    
    /* #region Public Static Methods */

    /**
     * @return array<int, string>
     */
    public static function aliases(

    ) :array {
        return [
            'rgb',
            'rgba',
            'srgb',
            's-rgb',
            's_rgb',
        ];
    }
    
    /* #endregion */
    
    /* #region Public Methods */ 

    public function change(
        \Stringable|string|int|float|null $red       = null,
        \Stringable|string|int|float|null $green     = null,
        \Stringable|string|int|float|null $blue      = null,
        \Stringable|string|int|float|null $opacity   = null,
        Rgb|null                          $fallback  = null,
        bool|null                         $throw     = null,
    ) :Rgb {
        $changeThrow = $throw ?? true;

        /** @var Rgb $result */
        $result = ColorFactory::newRgb(
            value    : [
                Util::changeCoordinate($this->red,     $red,     false, $changeThrow),
                Util::changeCoordinate($this->green,   $green,   false, $changeThrow),
                Util::changeCoordinate($this->blue,    $blue,    false, $changeThrow),
                Util::changeCoordinate($this->opacity, $opacity, false, $changeThrow),
            ],
            from     : $this::space(),
            fallback : $fallback,
            throw    : $throw,
        );

        return $result;
    }
    
    public function stringify(
        bool|null $legacy    = null,
        bool|null $alpha     = null,
        int|null  $precision = null,
    ) :string {
        return RgbConverter::stringify(
            red       : $this->red,
            green     : $this->green,
            blue      : $this->blue,
            opacity   : $this->opacity,
            legacy    : $legacy,
            alpha     : $alpha,
            precision : $precision,
        );
    }

    /* #endregion */
    
}