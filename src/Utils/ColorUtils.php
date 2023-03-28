<?php

namespace TotalCMS\Utils;

use TotalCMS\Domain\Property\Data\ColorData;
use UnexpectedValueException;

/**
 * Color Utilities.
 */
class ColorUtils
{
    /**
     * Auto darkens/lightens by 10% for subtle gradients.
     * Set this to FALSE to adjust automatic shade to be between given color
     * and black (for darken) or white (for lighten).
     */
    public const DEFAULT_ADJUST = 10;

    public const COLORNAMESTOHEX = [
        'aliceblue'            => 'F0F8FF',
        'antiquewhite'         => 'FAEBD7',
        'aqua'                 => '00FFFF',
        'aquamarine'           => '7FFFD4',
        'azure'                => 'F0FFFF',
        'beige'                => 'F5F5DC',
        'bisque'               => 'FFE4C4',
        'black'                => '000000',
        'blanchedalmond'       => 'FFEBCD',
        'blue'                 => '0000FF',
        'blueviolet'           => '8A2BE2',
        'brown'                => 'A52A2A',
        'burlywood'            => 'DEB887',
        'cadetblue'            => '5F9EA0',
        'chartreuse'           => '7FFF00',
        'chocolate'            => 'D2691E',
        'coral'                => 'FF7F50',
        'cornflowerblue'       => '6495ED',
        'cornsilk'             => 'FFF8DC',
        'crimson'              => 'DC143C',
        'cyan'                 => '00FFFF',
        'darkblue'             => '00008B',
        'darkcyan'             => '008B8B',
        'darkgoldenrod'        => 'B8860B',
        'darkgray'             => 'A9A9A9',
        'darkgreen'            => '006400',
        'darkgrey'             => 'A9A9A9',
        'darkkhaki'            => 'BDB76B',
        'darkmagenta'          => '8B008B',
        'darkolivegreen'       => '556B2F',
        'darkorange'           => 'FF8C00',
        'darkorchid'           => '9932CC',
        'darkred'              => '8B0000',
        'darksalmon'           => 'E9967A',
        'darkseagreen'         => '8FBC8F',
        'darkslateblue'        => '483D8B',
        'darkslategray'        => '2F4F4F',
        'darkslategrey'        => '2F4F4F',
        'darkturquoise'        => '00CED1',
        'darkviolet'           => '9400D3',
        'deeppink'             => 'FF1493',
        'deepskyblue'          => '00BFFF',
        'dimgray'              => '696969',
        'dimgrey'              => '696969',
        'dodgerblue'           => '1E90FF',
        'firebrick'            => 'B22222',
        'floralwhite'          => 'FFFAF0',
        'forestgreen'          => '228B22',
        'fuchsia'              => 'FF00FF',
        'gainsboro'            => 'DCDCDC',
        'ghostwhite'           => 'F8F8FF',
        'gold'                 => 'FFD700',
        'goldenrod'            => 'DAA520',
        'gray'                 => '808080',
        'green'                => '008000',
        'greenyellow'          => 'ADFF2F',
        'grey'                 => '808080',
        'honeydew'             => 'F0FFF0',
        'hotpink'              => 'FF69B4',
        'indianred'            => 'CD5C5C',
        'indigo'               => '4B0082',
        'ivory'                => 'FFFFF0',
        'khaki'                => 'F0E68C',
        'lavender'             => 'E6E6FA',
        'lavenderblush'        => 'FFF0F5',
        'lawngreen'            => '7CFC00',
        'lemonchiffon'         => 'FFFACD',
        'lightblue'            => 'ADD8E6',
        'lightcoral'           => 'F08080',
        'lightcyan'            => 'E0FFFF',
        'lightgoldenrodyellow' => 'FAFAD2',
        'lightgray'            => 'D3D3D3',
        'lightgreen'           => '90EE90',
        'lightgrey'            => 'D3D3D3',
        'lightpink'            => 'FFB6C1',
        'lightsalmon'          => 'FFA07A',
        'lightseagreen'        => '20B2AA',
        'lightskyblue'         => '87CEFA',
        'lightslategray'       => '778899',
        'lightslategrey'       => '778899',
        'lightsteelblue'       => 'B0C4DE',
        'lightyellow'          => 'FFFFE0',
        'lime'                 => '00FF00',
        'limegreen'            => '32CD32',
        'linen'                => 'FAF0E6',
        'magenta'              => 'FF00FF',
        'maroon'               => '800000',
        'mediumaquamarine'     => '66CDAA',
        'mediumblue'           => '0000CD',
        'mediumorchid'         => 'BA55D3',
        'mediumpurple'         => '9370D0',
        'mediumseagreen'       => '3CB371',
        'mediumslateblue'      => '7B68EE',
        'mediumspringgreen'    => '00FA9A',
        'mediumturquoise'      => '48D1CC',
        'mediumvioletred'      => 'C71585',
        'midnightblue'         => '191970',
        'mintcream'            => 'F5FFFA',
        'mistyrose'            => 'FFE4E1',
        'moccasin'             => 'FFE4B5',
        'navajowhite'          => 'FFDEAD',
        'navy'                 => '000080',
        'oldlace'              => 'FDF5E6',
        'olive'                => '808000',
        'olivedrab'            => '6B8E23',
        'orange'               => 'FFA500',
        'orangered'            => 'FF4500',
        'orchid'               => 'DA70D6',
        'palegoldenrod'        => 'EEE8AA',
        'palegreen'            => '98FB98',
        'paleturquoise'        => 'AFEEEE',
        'palevioletred'        => 'DB7093',
        'papayawhip'           => 'FFEFD5',
        'peachpuff'            => 'FFDAB9',
        'peru'                 => 'CD853F',
        'pink'                 => 'FFC0CB',
        'plum'                 => 'DDA0DD',
        'powderblue'           => 'B0E0E6',
        'purple'               => '800080',
        'red'                  => 'FF0000',
        'rosybrown'            => 'BC8F8F',
        'royalblue'            => '4169E1',
        'saddlebrown'          => '8B4513',
        'salmon'               => 'FA8072',
        'sandybrown'           => 'F4A460',
        'seagreen'             => '2E8B57',
        'seashell'             => 'FFF5EE',
        'sienna'               => 'A0522D',
        'silver'               => 'C0C0C0',
        'skyblue'              => '87CEEB',
        'slateblue'            => '6A5ACD',
        'slategray'            => '708090',
        'slategrey'            => '708090',
        'snow'                 => 'FFFAFA',
        'springgreen'          => '00FF7F',
        'steelblue'            => '4682B4',
        'tan'                  => 'D2B48C',
        'teal'                 => '008080',
        'thistle'              => 'D8BFD8',
        'tomato'               => 'FF6347',
        'turquoise'            => '40E0D0',
        'violet'               => 'EE82EE',
        'wheat'                => 'F5DEB3',
        'white'                => 'FFFFFF',
        'whitesmoke'           => 'F5F5F5',
        'yellow'               => 'FFFF00',
        'yellowgreen'          => '9ACD32',
    ];

    /**
     * Create a Color from CSS color name.
     *
     * @param string $name
     *
     * @throws UnexpectedValueException
     *
     * @return ColorData
     */
    public static function colorFromName(string $name): ColorData
    {
        $name = strtolower($name);
        if (isset(self::COLORNAMESTOHEX[$name])) {
            throw new UnexpectedValueException("Invalid color name provided ($name)");
        }

        return self::colorFromHex(self::COLORNAMESTOHEX[$name]);
    }

    /**
     * Throw excpetion if the color is not valid.
     *
     * @param array $rgb
     *
     * @throws UnexpectedValueException
     *
     * @return void
     */
    private static function validateRgb(array $rgb): void
    {
        if (empty($rgb) || !isset($rgb['r'], $rgb['g'], $rgb['b'])) {
            throw new UnexpectedValueException('Param was not an RGB array');
        }
    }

    /**
     * Create a Color from rgb values.
     *
     * @param array $rgb
     *
     * @throws UnexpectedValueException
     *
     * @return ColorData
     */
    public static function colorFromRgb(array $rgb): ColorData
    {
        self::validateRgb($rgb);

        $red   = $rgb['r'] / 255;
        $green = $rgb['g'] / 255;
        $blue  = $rgb['b'] / 255;
        $alpha = $rgb['a'] ?? 1;

        $min     = min($red, $green, $blue);
        $max     = max($red, $green, $blue);
        $delMax  = $max - $min;

        $h = 0;
        $s = 0;
        $l = ($max + $min) / 2;

        if ($delMax !== 0) {
            $s = $delMax / (2 - $max - $min);

            if ($l < 0.5) {
                $s = $delMax / ($max + $min);
            }

            $delR = ((($max - $red) / 6) + ($delMax / 2)) / $delMax;
            $delG = ((($max - $green) / 6) + ($delMax / 2)) / $delMax;
            $delB = ((($max - $blue) / 6) + ($delMax / 2)) / $delMax;

            if ($red == $max) {
                $h = $delB - $delG;
            } elseif ($green == $max) {
                $h = (1 / 3) + $delR - $delB;
            } elseif ($blue == $max) {
                $h = (2 / 3) + $delG - $delR;
            }

            if ($h < 0) {
                $h++;
            }
            if ($h > 1) {
                $h--;
            }
        }

        $hsl = [
            'h' => intval(round($h * 360)),
            's' => intval(round($s * 100)),
            'l' => intval(round($l * 100)),
            'a' => $alpha,
        ];

        return new ColorData($hsl);
    }

    /**
     * Create a Color from a hex string.
     *
     * @param string $hex
     *
     * @return ColorData
     */
    public static function colorFromHex(string $hex): ColorData
    {
        $hex = self::sanitizeHex($hex);
        $rgb = self::hexToRgb($hex);

        return self::colorFromRgb($rgb);
    }

    /**
     * Given a HEX string returns a RGB array equivalent.
     *
     * @param string $hex
     *
     * @return array RGB associative array
     */
    public static function hexToRgb(string $hex): array
    {
        // Sanity check
        $hex = self::sanitizeHex($hex);

        return [
            'r' => hexdec($hex[0] . $hex[1]),
            'g' => hexdec($hex[2] . $hex[3]),
            'b' => hexdec($hex[4] . $hex[5]),
        ];
    }

    /**
     * Given an RGB associative array returns the equivalent HEX string.
     *
     * @param array $rgb
     *
     * @throws UnexpectedValueException "Bad RGB Array"
     *
     * @return string Hex string
     */
    public static function rgbToHex(array $rgb): string
    {
        self::validateRgb($rgb);
        // Hex does not support alpha
        unset($rgb['a']);

        // Convert RGB to HEX + ensure 2 digits
        $hex = array_map(fn ($c) => str_pad(dechex((int)$c), 2, '0', STR_PAD_LEFT), $rgb);

        return implode('', $hex);
    }

    /**
     * Given an RGB associative array, returns CSS string output.
     *
     * @param array $rgb
     *
     * @throws UnexpectedValueException
     *
     * @return string rgb(r,g,b) string
     */
    public static function rgbToString(array $rgb): string
    {
        self::validateRgb($rgb);

        if (isset($rgb['a'])) {
            return sprintf('rgba(%d,%d,%d,%g)', ...$rgb);
        }

        return sprintf('rgb(%d,%d,%d)', ...$rgb);
    }

    /**
     * Convert a Color to hex.
     *
     * @param ColorData $color
     *
     * @return string HEX string
     */
    public static function colorToHex(ColorData $color): string
    {
        $rgb = array_map(function ($c) {
            // Convert to hex
            $hex = dechex($c);
            // Make sure we get 2 digits for decimals
            return (strlen('' . $hex) === 1) ? '0' . $hex : $hex;
        }, self::colorToRgb($color));

        return $rgb['r'] . $rgb['g'] . $rgb['b'];
    }

    /**
     * Convert a Color to rgb.
     *
     * @param ColorData $color
     *
     * @return array
     */
    public static function colorToRgb(ColorData $color): array
    {
        [$h, $s, $l, $a] = [$color->h / 360, $color->s / 100, $color->l / 100, $color->a];

        // If there's no saturation, the color is a greyscale,
        // so all three RGB values can be set to the lightness.
        // (Hue doesn't matter, because it's grey, not color)
        $r = $g = $b = $l * 255;

        if ($s != 0) {
            // calculate some temperary variables to make the calculation eaisier.
            $temp2 = ($l + $s) - ($s * $l);
            if ($l < 0.5) {
                $temp2 = $l * (1 + $s);
            }
            $temp1 = 2 * $l - $temp2;

            // run the calculated vars through hueToRgb to
            // calculate the RGB value.  Note that for the Red
            // value, we add a third (120 degrees), to adjust
            // the hue to the correct section of the circle for
            // red.  Simalarly, for blue, we subtract 1/3.
            $r = 255 * self::hueToRgb($temp1, $temp2, $h + (1 / 3));
            $g = 255 * self::hueToRgb($temp1, $temp2, $h);
            $b = 255 * self::hueToRgb($temp1, $temp2, $h - (1 / 3));
        }

        return [
            'r' => intval(round($r)),
            'g' => intval(round($g)),
            'b' => intval(round($b)),
            'a' => $a,
        ];
    }

    /**
     * Given a Hue, returns corresponding RGB value.
     *
     * @param float $temp1
     * @param float $temp2
     * @param float $hue
     *
     * @return float
     */
    private static function hueToRgb(float $temp1, float $temp2, float $hue): float
    {
        if ($hue < 0) {
            $hue++;
        }
        if ($hue > 1) {
            $hue--;
        }

        if ((6 * $hue) < 1) {
            return $temp1 + ($temp2 - $temp1) * 6 * $hue;
        } elseif ((2 * $hue) < 1) {
            return $temp2;
        } elseif ((3 * $hue) < 2) {
            return $temp1 + ($temp2 - $temp1) * ((2 / 3) - $hue) * 6;
        }

        return $temp1;
    }

    /**
     * Checks the HEX string for correct formatting and converts short format to long.
     *
     * @param string $hex
     *
     * @throws UnexpectedValueException
     *
     * @return string
     */
    private static function sanitizeHex(string $hex): string
    {
        // Strip # sign if it is present
        $color = str_replace('#', '', $hex);

        // Validate hex string
        if (!preg_match('/^[a-fA-F0-9]+$/', $color)) {
            throw new UnexpectedValueException('HEX color does not match format');
        }

        // Make sure it's 6 digits
        if (strlen($color) === 3) {
            $color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
        } elseif (strlen($color) !== 6) {
            throw new UnexpectedValueException('HEX color needs to be 6 or 3 digits long');
        }

        return $color;
    }

    /**
     * Darken a color.
     *
     * @param ColorData $color
     * @param int $amount
     *
     * @return ColorData
     */
    public static function darken(ColorData $color, int $amount = self::DEFAULT_ADJUST): ColorData
    {
        $hsl      = $color->transform();
        $hsl['l'] = $hsl['l'] * 100 - $amount;
        $hsl['l'] = $hsl['l'] < 0 ? 0 : $hsl['l'] / 100;

        return new ColorData($hsl);
    }

    /**
     * Lighten a color.
     *
     * @param ColorData $color
     * @param int $amount
     *
     * @return ColorData
     */
    public static function lighten(ColorData $color, int $amount = self::DEFAULT_ADJUST): ColorData
    {
        $hsl      = $color->transform();
        $hsl['l'] = ($hsl['l'] * 100) + $amount;
        $hsl['l'] = $hsl['l'] > 100 ? 1 : $hsl['l'] / 100;

        return new ColorData($hsl);
    }

    /**
     * Returns the complimentary color.
     *
     * @param ColorData $color
     *
     * @return ColorData
     */
    public static function complementary(ColorData $color): ColorData
    {
        $hsl = $color->transform();
        // Adjust Hue 180 degrees
        $hsl['h'] += ($hsl['h'] > 180) ? -180 : 180;

        return new ColorData($hsl);
    }

    /**
     * Returns whether or not a given color is considered "light".
     *
     * @param ColorData $color
     * @param int $lighterThan
     *
     * @return bool
     */
    public static function isLight(ColorData $color, int $lighterThan = 155): bool
    {
        [$r, $g, $b] = self::colorToRgb($color);
        $brightness  = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

        return $brightness > $lighterThan;
    }

    /**
     * Returns whether or not a given color is considered "dark".
     *
     * @param ColorData $color
     * @param int $darkerThan
     *
     * @return bool
     */
    public static function isDark(ColorData $color, int $darkerThan = 155): bool
    {
        return !self::isLight($color, $darkerThan);
    }

    /**
     * Mix 2 Colors.
     *
     * @param ColorData $color1
     * @param ColorData $color2
     *
     * @return ColorData
     */
    public static function mix(ColorData $color1, ColorData $color2): ColorData
    {
        $mixed = [
            'h' => ($color1->h + $color2->h) / 2,
            's' => ($color1->s + $color2->s) / 2,
            'l' => ($color1->l + $color2->l) / 2,
            'a' => ($color1->a + $color2->a) / 2,
        ];

        return new ColorData($mixed);
    }

    /**
     * Creates an array with two shades that can be used to make a gradient.
     *
     * @param int $amount Optional percentage amount you want your contrast color
     * @param ColorData $color
     *
     * @return array of ColorData
     */
    private static function gradientColors(ColorData $color, int $amount = self::DEFAULT_ADJUST): array
    {
        if (self::isLight($color)) {
            return [$color, self::darken($color, $amount)];
        }

        return [self::lighten($color, $amount), $color];
    }

    /**
     * Returns a CSS gradient string.
     *
     * @param ColorData $color
     * @param int $amount Optional: percentage amount to light/darken the gradient
     * @param string $direction
     *
     * @return string CSS3 gradient for chrome, safari, firefox, opera and IE10
     */
    public function linearGradient(ColorData $color, $amount = self::DEFAULT_ADJUST, string $direction = '180deg'): string
    {
        [$startColor, $endColor] = self::gradientColors($color, $amount);

        $css = "background-color:$color;";
        $css .= "background-image: linear-gradient($direction, $startColor, $endColor);";

        // Return our CSS
        return $css;
    }

    /**
     * Returns a CSS3 radial gradient.
     *
     * @param ColorData $color
     * @param int $amount Optional: percentage amount to light/darken the gradient
     * @param string $position
     *
     * @return string CSS3 gradient for chrome, safari, firefox, opera and IE10
     */
    public function radialGradient(ColorData $color, $amount = self::DEFAULT_ADJUST, string $position = 'circle at center'): string
    {
        [$startColor, $endColor] = self::gradientColors($color, $amount);

        $css = "background-color:$color;";
        $css .= "background-image: radial-gradient($position, $startColor, $endColor);";

        // Return our CSS
        return $css;
    }
}
