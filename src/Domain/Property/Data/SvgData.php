<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * SVG type property data.
 */
class SvgData extends PropertyData
{
    public string $svg;

    public function __construct(string $svg)
    {
        if (!self::verifySvg($svg)) {
            throw new \InvalidArgumentException('Invalid SVG');
        }
        $this->svg = $svg;
    }

    private static function verifySvg(string $svg): bool
    {
        $doc = new \DOMDocument();
        $doc->loadXML($svg);

        return $doc->getElementsByTagName('svg')->length > 0;
    }

    public function transform(): string
    {
        return (string)$this;
    }

    public function __toString(): string
    {
        return $this->svg;
    }
}
