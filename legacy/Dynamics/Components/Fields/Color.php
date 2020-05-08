<?php
namespace Dynamics\Components\Fields;

use Dynamics\Dynamics;
use \Exception;
use \Monolog\Logger;

// use function SSNepenthe\ColorUtils\{
//     alpha, blue, brightness, brightness_difference, color, color_difference,
//     contrast_ratio, green, hsl, hsla, hue, is_bright, is_light, lightness,
//     looks_bright, name, opacity, perceived_brightness, red, relative_luminance, rgb,
//     rgba, saturation
// };

//---------------------------------------------------------------------------------
// COLOR class
//---------------------------------------------------------------------------------
class Color
{
    private $color;
    private $logger;
    private $dir;

    public $alpha;
    public $hex;
    public $rgb;
    public $hsl;

    public function __construct($color, Logger $logger)
    {
        $this->logger = $logger;

        $this->logger->debug("Creating new Color for:", [$color]);

        switch (gettype($color)) {
            case "string":
                if (empty(trim($color))) {
                    $e = new Exception("No color provided.");
                    $this->exception($e, $color);
                } else {
                    // Passed color as string (hex, rgb, rgba, hsl, hsla)
                    $this->color = \SSNepenthe\ColorUtils\color($color);
                }
                break;
            case "array":
                // Existing color object from CMS?
                // Check to make sure that the value that we need are set
                if (isset($color["rgb"]) && isset($color["alpha"]) && gettype($color["rgb"]) === "array") {
                    $rgba = $color["rgb"];
                    $rgba[] = $color["alpha"];
                    $this->color = \SSNepenthe\ColorUtils\color(...$rgba);
                    break;
                }
                // Move to default action, unknown color definition
            default:
                try {
                    // try creating the color with whatever was passed
                    $this->color = \SSNepenthe\ColorUtils\color(...$color);
                } catch (Exception $e) {
                    $this->exception($e, $color);
                }
        }

        $rgb = $this->color->getRgb();
        $hsl = $this->color->getHsl();

        $this->alpha = $this->alpha();
        $this->hex   = $this->hex(false);
        $this->rgb   = [$rgb->getRed(), $rgb->getGreen(), $rgb->getBlue()];
        $this->hsl   = [$hsl->getHue(), $hsl->getSaturation(), $hsl->getLightness()];
    }

    private function exception(Exception $e, $color) : void
    {
        // Error out - unknown color defined
        $this->logger->warn("Unknown Color Definition. Using transparent color: ".$e->getMessage(), [$color,$e]);
        // default to tranparent color
        $this->color = \SSNepenthe\ColorUtils\color('rgba(0,0,0,0)');
    }

    private function newAlpha(...$args) : float
    {
        if (isset($args[0]) && is_numeric($args[0])) {
            // if an alpha value is passed, use that instead
            return floatval($args[0]);
        }
        return $this->alpha();
    }

    public function alpha() : float
    {
        return $this->color->getAlpha();
    }

    public function hex(bool $hash = true) : string
    {
        $rgb = $this->color->getRgb();
        $format = $hash ? "#%02x%02x%02x" : "%02x%02x%02x";
        return sprintf($format, $rgb->getRed(), $rgb->getGreen(), $rgb->getBlue());
    }

    public function hsl(...$args) : string
    {
        $alpha = $this->newAlpha(...$args);
        return $this->color->getHsl()->with(['alpha' => $alpha]);
    }

    public function rgb(...$args) : string
    {
        $alpha = $this->newAlpha(...$args);
        return $this->color->getRgb()->with(['alpha' => $alpha]);
    }

    public function complement() : Color
    {
        $rgb = \SSNepenthe\ColorUtils\complement($this->color)->getRgb();
        return new Color("$rgb", $this->logger);
    }

    public function invert() : Color
    {
        $rgb = \SSNepenthe\ColorUtils\invert($this->color)->getRgb();
        return new Color("$rgb", $this->logger);
    }

    public function toArray() : array
    {
        return [
            "alpha" => $this->alpha,
            "hex"   => $this->hex,
            "rgb"   => $this->rgb,
            "hsl"   => $this->hsl
        ];
    }

    public function toJson() : string
    {
        return json_encode($this->toArray());
    }
}
