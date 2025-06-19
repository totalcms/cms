<?php

namespace TotalCMS\Domain\Property\Data;

use enshrined\svgSanitize\Sanitizer;

/**
 * SVG type property data with sanitization.
 *
 * Automatically sanitizes SVG content to prevent XSS attacks while
 * preserving valid SVG functionality.
 */
class SvgData extends PropertyData
{
	private static ?Sanitizer $sanitizer = null;

	public function __construct(public string $svg = '', public array $settings = [])
	{
		if (!empty($svg)) {

			if ($settings['svgclean'] ?? true) {
				// Sanitize the SVG content before validation
				$this->svg = $this->sanitizeSvg($svg);
			}

			// Verify it's still valid SVG after sanitization
			if (!self::verifySvg($this->svg)) {
				throw new \InvalidArgumentException('Invalid SVG content');
			}
		}
	}

	/**
	 * Sanitize SVG content to remove potentially dangerous elements.
	 */
	private function sanitizeSvg(string $svg): string
	{
		$sanitizer = $this->getSanitizer();

		try {
			// Sanitize the SVG content
			$cleanSvg = $sanitizer->sanitize($svg);

			// Return empty string if sanitization failed
			return $cleanSvg ?: '';
		} catch (\Exception $e) {
			// If sanitization fails, return empty string
			return '';
		}
	}

	/**
	 * Get or create SVG sanitizer instance.
	 */
	private function getSanitizer(): Sanitizer
	{
		if (self::$sanitizer === null) {
			self::$sanitizer = new Sanitizer();

			// Configure sanitizer for security
			self::$sanitizer->removeRemoteReferences(true);
			self::$sanitizer->minify(false); // Keep readable for debugging
		}

		return self::$sanitizer;
	}

	/**
	 * Verify that the content is valid SVG.
	 */
	private static function verifySvg(string $svg): bool
	{
		if (empty($svg)) {
			return false;
		}

		// Suppress XML errors for validation
		$prevUseErrors = libxml_use_internal_errors(true);

		try {
			$doc = new \DOMDocument();
			// Use secure XML loading options
			$doc->loadXML($svg, LIBXML_NOENT | LIBXML_NONET | LIBXML_NOBLANKS);

			// Check for XML errors
			$errors = libxml_get_errors();
			if (!empty($errors)) {
				return false;
			}

			// Must contain at least one SVG element
			return $doc->getElementsByTagName('svg')->length > 0;
		} catch (\Exception $e) {
			return false;
		} finally {
			// Restore previous settings
			libxml_use_internal_errors($prevUseErrors);
			libxml_clear_errors();
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
