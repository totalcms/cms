<?php

namespace TotalCMS\Domain\Property\Data;

use TotalCMS\Utils\SVGSanitizer;

/**
 * SVG type property data with sanitization.
 *
 * Automatically sanitizes SVG content to prevent XSS attacks while
 * preserving valid SVG functionality.
 */
class SvgData extends PropertyData
{
	public function __construct(public string $svg = '', public array $settings = [])
	{
		if (!empty($svg)) {
			if ($settings['svgclean'] ?? true) {
				// Sanitize the SVG content before validation
				$this->svg = SVGSanitizer::sanitizeAndValidate($svg);
			}
			if (!SVGSanitizer::isValidSvg($this->svg)) {
				throw new \InvalidArgumentException('Invalid SVG content');
			}
		}
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
