<?php

namespace TotalCMS\Utils\Color\Colors;

use       TotalCMS\Utils\Color\Color;
use       TotalCMS\Utils\Color\ColorFactory;
use       TotalCMS\Utils\Color\ColorInterface;
use       TotalCMS\Utils\Color\Util;
use       TotalCMS\Utils\Color\Converters\XyzD65 as XyzD65Converter;

class      XyzD65
extends    Color
implements ColorInterface {

    /* #region Constructor */

    public function __construct(
        public readonly float $x       = 0,
        public readonly float $y       = 0,
        public readonly float $z       = 0,
        public readonly float $opacity = 1,
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
            'xyz-d65',
            'xyz_d65',
            'xyzd65',
            'xyz',
        ];
    }
    
    /* #endregion */
    
    /* #region Public Methods */

    public function change(
        \Stringable|string|int|float|null $x        = null,
        \Stringable|string|int|float|null $y        = null,
        \Stringable|string|int|float|null $z        = null,
        \Stringable|string|int|float|null $opacity  = null,
        XyzD65|null                       $fallback = null,
        bool|null                         $throw    = null,
    ) :XyzD65 {
        $changeThrow = $throw ?? true;

        /** @var XyzD65 $result */
        $result = ColorFactory::newXyzD65(
            value    : [
                Util::changeCoordinate($this->x,       $y,       false, $changeThrow),
                Util::changeCoordinate($this->y,       $y,       false, $changeThrow),
                Util::changeCoordinate($this->z,       $z,       false, $changeThrow),
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
        return XyzD65Converter::stringify(
            x         : $this->x,
            y         : $this->y,
            z         : $this->z,
            opacity   : $this->opacity,
            alpha     : $alpha,
            precision : $precision,
        );
    }

    /* #endregion */


}