<?php

namespace TotalCMS\Utils\Color;

use       TotalCMS\Utils\Color\Colors\HexRgb;
use       TotalCMS\Utils\Color\Colors\Hsl;
use       TotalCMS\Utils\Color\Colors\LinRgb;
use       TotalCMS\Utils\Color\Colors\OkLab;
use       TotalCMS\Utils\Color\Colors\OkLch;
use       TotalCMS\Utils\Color\Colors\Rgb;
use       TotalCMS\Utils\Color\Colors\XyzD65;

/**
 * An immutable object representing a color expressed in a precise and supported color space.
 *
 * It can be converted to another supported color space using one of the to...() methods.
 * Variant instances can be created with the change() method.
 *
 */
interface ColorInterface
extends   \Stringable {

    /* #region Magic Methods */

    /**
     * Returns the color as a CSS string (examples: '#ff0000', 'rgb(100% 0% 0% / 100%)'...).
     * This method is a shortcut to calling the stringify() method with its default parameters.
     *
     * @return string
     */
    public function __toString(

    ) :string;

    /* #endregion */

    /* #region Public Static Methods */

    /**
     * Returns an array containing all supported aliases for the ColorSpace of the current color.
     *
     * @return array
     */
    public static function aliases(

    ) :array;

    /**
     * Returns the ColorSpace instance corresponding to the current color.
     *
     * @return ColorSpace
     */
    public static function space(

    ) :ColorSpace;

    /* #endregion */

    /* #region Public Methods */

    /**
     * Returns a new ColorInterface instance of the same class, with modified coordinates.
     * Each implementation of this method may add its own parameters, depending on the corresponding color space.
     *
     * @return ColorInterface
     */
    public function change(

    ) :self;

    /**
     * Returns an array containing all coordinates of the current color.
     *
     * @return array
     */
    public function coordinates(

    ) :array;

    /**
     * Returns the color as a CSS string (examples: '#ff0000', 'rgb(100% 0% 0% / 100%)'...)
     * Each implementation of this method may add its own parameters, depending on the corresponding color space.
     *
     * @return string
     */
    public function stringify(

    ) :string;

    /**
     * Returns a new ColorInterface instance corresponding to the current color converted into the $to color space.
     *
     * @param  ColorSpace|\Stringable|string|null $to       The desired output color space (can be an instance of the ColorSpace enum or a stringable alias)
     * @param  ColorInterface|null                $fallback A ColorInterface instance used as a fallback in case of failure
     * @param  boolean|null                       $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return ColorInterface                               The converted color object
     */
    public function to(
        ColorSpace|\Stringable|string|null $to       = null,
        ColorInterface|null                $fallback = null,
        bool|null                          $throw    = null,
    ) :ColorInterface;

    /**
     * Returns a new Colors\HexRgb instance corresponding to the current color converted into the HexRgb color space.
     *
     * @param  HexRgb|null  $fallback A Colors\HexRgb instance used as a fallback in case of failure
     * @param  boolean|null $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return HexRgb                 The converted color object
     */
    public function toHexRgb(
        HexRgb|null $fallback = null,
        bool|null   $throw    = null,
    ) :HexRgb;

    /**
     * Returns a new Colors\Hsl instance corresponding to the current color converted into the Hsl color space.
     *
     * @param  Hsl|null     $fallback A Colors\Hsl instance used as a fallback in case of failure
     * @param  boolean|null $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return Hsl                    The converted color object
     */
    public function toHsl(
        Hsl|null  $fallback = null,
        bool|null $throw    = null,
    ) :Hsl;

    /**
     * Returns a new Colors\LinRgb instance corresponding to the current color converted into the LinRgb color space.
     *
     * @param  LinRgb|null  $fallback A Colors\LinRgb instance used as a fallback in case of failure
     * @param  boolean|null $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return LinRgb                 The converted color object
     */
    public function toLinRgb(
        LinRgb|null $fallback = null,
        bool|null   $throw    = null,
    ) :LinRgb;

    /**
     * Returns a new Colors\OkLab instance corresponding to the current color converted into the OkLab color space.
     *
     * @param  OkLab|null   $fallback A Colors\OkLab instance used as a fallback in case of failure
     * @param  boolean|null $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return OkLab                  The converted color object
     */
    public function toOkLab(
        OkLab|null $fallback = null,
        bool|null  $throw    = null,
    ) :OkLab;

    /**
     * Returns a new Colors\OkLch instance corresponding to the current color converted into the OkLch color space.
     *
     * @param  OkLch|null   $fallback A Colors\OkLch instance used as a fallback in case of failure
     * @param  boolean|null $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return OkLch                  The converted color object
     */
    public function toOkLch(
        OkLch|null $fallback = null,
        bool|null  $throw    = null,
    ) :OkLch;

    /**
     * Returns a new Colors\Rgb instance corresponding to the current color converted into the Rgb color space.
     *
     * @param  Rgb|null     $fallback A Colors\Rgb instance used as a fallback in case of failure
     * @param  boolean|null $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return Rgb                    The converted color object
     */
    public function toRgb(
        Rgb|null  $fallback = null,
        bool|null $throw    = null,
    ) :Rgb;

    /**
     * Returns a new Colors\XyzD65 instance corresponding to the current color converted into the XyzD65 color space.
     *
     * @param  XyzD65|null  $fallback A Colors\XyzD65 instance used as a fallback in case of failure
     * @param  boolean|null $throw    If false the method will not throw exceptions, $fallback will be returned instead
     *
     * @return XyzD65                 The converted color object
     */
    public function toXyzD65(
        XyzD65|null $fallback = null,
        bool|null   $throw    = null,
    ) :XyzD65;

    /* #endregion */

}
