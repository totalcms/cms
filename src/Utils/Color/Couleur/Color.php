<?php

namespace TotalCMS\Utils\Color\Couleur;

use       TotalCMS\Utils\Color\Couleur\Colors\HexRgb;
use       TotalCMS\Utils\Color\Couleur\Colors\Hsl;
use       TotalCMS\Utils\Color\Couleur\Colors\LinRgb;
use       TotalCMS\Utils\Color\Couleur\Colors\OkLab;
use       TotalCMS\Utils\Color\Couleur\Colors\OkLch;
use       TotalCMS\Utils\Color\Couleur\Colors\Rgb;
use       TotalCMS\Utils\Color\Couleur\Colors\XyzD65;

/**
 * An immutable object representing a color expressed in a precise and supported color space.
 *
 * It can be converted to another supported color space using one of the to...() methods.
 * Variant instances can be created with the change() method.
 *
 * This class is abstract so it can not be instanciated directly.
 * It is inherited by all classes in the TotalCMS\Utils\Color\Couleur\Colors namespace.
 */
abstract class Color {

    /* #region Magic Methods */

    /**
     * Returns the color as a CSS string (examples: '#ff0000', 'rgb(100% 0% 0% / 100%)'...).
     * This method is a shortcut to calling the stringify() method with its default parameters.
     *
     * @return string
     */
    public function __toString(

    ) :string {
        return $this->stringify();
    }

    /* #endregion */

    /* #region Public Static Methods */

    /**
     * Returns the ColorSpace instance corresponding to the current color.
     *
     * @return ColorSpace
     */
    public static function space(

    ) :ColorSpace {
        return ColorSpace::from(static::class);
    }

    /* #endregion */

    /* #region Public Methods */

    /**
     * Returns an array containing all coordinates of the current color.
     *
     * @return array
     */
    public function coordinates(

    ) :array {
        return \array_values(\get_object_vars($this));
    }

    /**
     * Returns the color as a CSS string (examples: '#ff0000', 'rgb(100% 0% 0% / 100%)'...)
     * Each implementation of this method may add its own parameters, depending on the corresponding color space.
     *
     * @return string
     */
    public function stringify(

    ) :string {
        return \implode(', ', $this->coordinates());
    }

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
    ) :ColorInterface {
        return ColorFactory::new(
            value    : $this->coordinates(),
            to       : $to,
            from     : $this::space(),
            fallback : $fallback,
            throw    : $throw,
        );
    }

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
    ) :HexRgb {
        return $this->to(
            to       : ColorSpace::HexRgb,
            fallback : $fallback,
            throw    : $throw,
        );
    }

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
    ) :Hsl {
        return $this->to(
            to       : ColorSpace::Hsl,
            fallback : $fallback,
            throw    : $throw,
        );
    }

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
    ) :LinRgb {
        return $this->to(
            to       : ColorSpace::LinRgb,
            fallback : $fallback,
            throw    : $throw,
        );
    }

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
    ) :OkLab {
        return $this->to(
            to       : ColorSpace::OkLab,
            fallback : $fallback,
            throw    : $throw,
        );
    }

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
    ) :OkLch {
        return $this->to(
            to       : ColorSpace::OkLch,
            fallback : $fallback,
            throw    : $throw,
        );
    }

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
    ) :Rgb {
        return $this->to(
            to       : ColorSpace::Rgb,
            fallback : $fallback,
            throw    : $throw,
        );
    }

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
    ) :XyzD65 {
        return $this->to(
            to       : ColorSpace::XyzD65,
            fallback : $fallback,
            throw    : $throw,
        );
    }

    /* #endregion */

}
