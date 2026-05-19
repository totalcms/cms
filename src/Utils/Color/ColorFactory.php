<?php

namespace TotalCMS\Utils\Color;

use       TotalCMS\Utils\Color\Colors\HexRgb;
use       TotalCMS\Utils\Color\Colors\Hsl;
use       TotalCMS\Utils\Color\Colors\LinRgb;
use       TotalCMS\Utils\Color\Colors\OkLab;
use       TotalCMS\Utils\Color\Colors\OkLch;
use       TotalCMS\Utils\Color\Colors\Rgb;
use       TotalCMS\Utils\Color\Colors\XyzD65;
use       TotalCMS\Utils\Color\Util;

abstract class ColorFactory {

    /* #region Public Static Methods */

    /**
     * Returns a new ColorInterface instance corresponding to $value.
     *
     * @param  mixed                              $value    A color string (like '#ff0000' or 'rgb(255,0,0)') or a coordinates array (like [ 'ff', '00', '00' ] or [ 255, 0, 0])
     * @param  ColorSpace|\Stringable|string|null $to       The desired output color space (if not specified it will be the same as $from)
     * @param  ColorSpace|\Stringable|string|null $from     The input color space (if not specified it will be automatically guessed by interpreting the format of $value)
     * @param  ColorInterface|null                $fallback A ColorInterface instance used as a fallback in case of failure
     * @param  boolean|null                       $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return ColorInterface|null
     */
    public static function new(
        mixed                              $value,
        ColorSpace|\Stringable|string|null $to        = null,
        ColorSpace|\Stringable|string|null $from      = null,
        ColorInterface|null                $fallback  = null,
        bool|null                          $throw     = null,
    ) :ColorInterface|null {
        $throw ??= !$fallback;

        Util::setFromAndTo(
            value    : $value,
            to       : $to,
            from     : $from,
            throw    : $throw,
        );

        if (!($from instanceof ColorSpace)
        || !($to instanceof ColorSpace)) {
            return $fallback;
        }

        $coords = Util::toColor(
            value     : $value,
            to        : $to,
            from      : $from,
            fallback  : null,
            throw     : $throw,
        );

        if ($coords === null) {
            return $fallback;
        }

        // HexRgb takes string coords, the others take float coords.
        if ($to === ColorSpace::HexRgb) {
            /** @var array<int, string> $hexCoords */
            $hexCoords = $coords;
            return new HexRgb(... $hexCoords);
        }

        /** @var array<int, float> $floatCoords */
        $floatCoords = $coords;
        return match ($to) {
            ColorSpace::Hsl    => new Hsl(... $floatCoords),
            ColorSpace::LinRgb => new LinRgb(... $floatCoords),
            ColorSpace::OkLab  => new OkLab(... $floatCoords),
            ColorSpace::OkLch  => new OkLch(... $floatCoords),
            ColorSpace::Rgb    => new Rgb(... $floatCoords),
            ColorSpace::XyzD65 => new XyzD65(... $floatCoords),
        };
    }

    /**
     * Returns a new Colors\HexRgb instance corresponding to $value.
     *
     * @param  mixed                              $value    A color string (like '#ff0000' or 'rgb(255,0,0)') or a coordinates array (like [ 'ff', '00', '00' ] or [ 255, 0, 0])
     * @param  ColorSpace|\Stringable|string|null $from     The input color space (if not specified it will be automatically guessed by interpreting the format of $value)
     * @param  HexRgb|null                        $fallback A Colors\HexRgb instance used as a fallback in case of failure
     * @param  boolean|null                       $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return HexRgb|null
     */
    public static function newHexRgb(
        mixed                              $value,
        ColorSpace|\Stringable|string|null $from      = null,
        HexRgb|null                        $fallback  = null,
        bool|null                          $throw     = null,
    ) :HexRgb|null {
        /** @var HexRgb|null $result */
        $result = static::new(
            value     : $value,
            to        : ColorSpace::HexRgb,
            from      : $from,
            fallback  : $fallback,
            throw     : $throw,
        );
        return $result;
    }

    /**
     * Returns a new Colors\Hsl instance corresponding to $value.
     *
     * @param  mixed                              $value    A color string (like '#ff0000' or 'rgb(255,0,0)') or a coordinates array (like [ 'ff', '00', '00' ] or [ 255, 0, 0])
     * @param  ColorSpace|\Stringable|string|null $from     The input color space (if not specified it will be automatically guessed by interpreting the format of $value)
     * @param  Hsl|null                           $fallback A Colors\Hsl instance used as a fallback in case of failure
     * @param  boolean|null                       $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return Hsl|null
     */
    public static function newHsl(
        mixed                              $value,
        ColorSpace|\Stringable|string|null $from      = null,
        Hsl|null                           $fallback  = null,
        bool|null                          $throw     = null,
    ) :Hsl|null {
        /** @var Hsl|null $result */
        $result = static::new(
            value     : $value,
            to        : ColorSpace::Hsl,
            from      : $from,
            fallback  : $fallback,
            throw     : $throw,
        );
        return $result;
    }

    /**
     * Returns a new Colors\LinRgb instance corresponding to $value.
     *
     * @param  mixed                              $value    A color string (like '#ff0000' or 'rgb(255,0,0)') or a coordinates array (like [ 'ff', '00', '00' ] or [ 255, 0, 0])
     * @param  ColorSpace|\Stringable|string|null $from     The input color space (if not specified it will be automatically guessed by interpreting the format of $value)
     * @param  LinRgb|null                        $fallback A Colors\LinRgb instance used as a fallback in case of failure
     * @param  boolean|null                       $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return LinRgb|null
     */
    public static function newLinRgb(
        mixed                              $value,
        ColorSpace|\Stringable|string|null $from      = null,
        LinRgb|null                        $fallback  = null,
        bool|null                          $throw     = null,
    ) :LinRgb|null {
        /** @var LinRgb|null $result */
        $result = static::new(
            value     : $value,
            to        : ColorSpace::LinRgb,
            from      : $from,
            fallback  : $fallback,
            throw     : $throw,
        );
        return $result;
    }

    /**
     * Returns a new Colors\OkLab instance corresponding to $value.
     *
     * @param  mixed                              $value    A color string (like '#ff0000' or 'rgb(255,0,0)') or a coordinates array (like [ 'ff', '00', '00' ] or [ 255, 0, 0])
     * @param  ColorSpace|\Stringable|string|null $from     The input color space (if not specified it will be automatically guessed by interpreting the format of $value)
     * @param  OkLab|null                         $fallback A Colors\OkLab instance used as a fallback in case of failure
     * @param  boolean|null                       $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return OkLab|null
     */
    public static function newOkLab(
        mixed                              $value,
        ColorSpace|\Stringable|string|null $from      = null,
        OkLab|null                         $fallback  = null,
        bool|null                          $throw     = null,
    ) :OkLab|null {
        /** @var OkLab|null $result */
        $result = static::new(
            value     : $value,
            to        : ColorSpace::OkLab,
            from      : $from,
            fallback  : $fallback,
            throw     : $throw,
        );
        return $result;
    }

    /**
     * Returns a new Colors\OkLch instance corresponding to $value.
     *
     * @param  mixed                              $value    A color string (like '#ff0000' or 'rgb(255,0,0)') or a coordinates array (like [ 'ff', '00', '00' ] or [ 255, 0, 0])
     * @param  ColorSpace|\Stringable|string|null $from     The input color space (if not specified it will be automatically guessed by interpreting the format of $value)
     * @param  OkLch|null                         $fallback A Colors\OkLch instance used as a fallback in case of failure
     * @param  boolean|null                       $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return OkLch|null
     */
    public static function newOkLch(
        mixed                              $value,
        ColorSpace|\Stringable|string|null $from      = null,
        OkLch|null                         $fallback  = null,
        bool|null                          $throw     = null,
    ) :OkLch|null {
        /** @var OkLch|null $result */
        $result = static::new(
            value     : $value,
            to        : ColorSpace::OkLch,
            from      : $from,
            fallback  : $fallback,
            throw     : $throw,
        );
        return $result;
    }

    /**
     * Returns a new Colors\Rgb instance corresponding to $value.
     *
     * @param  mixed                              $value    A color string (like '#ff0000' or 'rgb(255,0,0)') or a coordinates array (like [ 'ff', '00', '00' ] or [ 255, 0, 0])
     * @param  ColorSpace|\Stringable|string|null $from     The input color space (if not specified it will be automatically guessed by interpreting the format of $value)
     * @param  Rgb|null                           $fallback A Colors\Rgb instance used as a fallback in case of failure
     * @param  boolean|null                       $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return Rgb|null
     */
    public static function newRgb(
        mixed                              $value,
        ColorSpace|\Stringable|string|null $from      = null,
        Rgb|null                           $fallback  = null,
        bool|null                          $throw     = null,
    ) :Rgb|null {
        /** @var Rgb|null $result */
        $result = static::new(
            value     : $value,
            to        : ColorSpace::Rgb,
            from      : $from,
            fallback  : $fallback,
            throw     : $throw,
        );
        return $result;
    }

    /**
     * Returns a new Colors\XyzD65 instance corresponding to $value.
     *
     * @param  mixed                              $value    A color string (like '#ff0000' or 'rgb(255,0,0)') or a coordinates array (like [ 'ff', '00', '00' ] or [ 255, 0, 0])
     * @param  ColorSpace|\Stringable|string|null $from     The input color space (if not specified it will be automatically guessed by interpreting the format of $value)
     * @param  XyzD65|null                        $fallback A Colors\XyzD65 instance used as a fallback in case of failure
     * @param  boolean|null                       $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return XyzD65|null
     */
    public static function newXyzD65(
        mixed                              $value,
        ColorSpace|\Stringable|string|null $from      = null,
        XyzD65|null                        $fallback  = null,
        bool|null                          $throw     = null,
    ) :XyzD65|null {
        /** @var XyzD65|null $result */
        $result = static::new(
            value     : $value,
            to        : ColorSpace::XyzD65,
            from      : $from,
            fallback  : $fallback,
            throw     : $throw,
        );
        return $result;
    }

    /* #endregion */

}
