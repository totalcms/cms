<?php

namespace TotalCMS\Domain\Security\Sanitization;

use enshrined\svgSanitize\Sanitizer;

/**
 * SVG Sanitizer for preventing XSS attacks while preserving valid SVG functionality.
 */
class SVGSanitizer
{
	private static ?Sanitizer $sanitizer = null;

	public static function sanitize(string $svg): string
	{
		if ($svg === '') {
			return '';
		}
		$sanitizer = self::getSanitizer();

		try {
			// Sanitize the SVG content
			$cleanSvg = $sanitizer->sanitize($svg);

			// Return empty string if sanitization failed
			return $cleanSvg ?: '';
		} catch (\Exception) {
			// If sanitization fails, return empty string
			return '';
		}
	}

	/**
	 * Verify that the content is valid SVG.
	 */
	public static function isValidSvg(string $svg): bool
	{
		if ($svg === '') {
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
			if ($errors !== []) {
				return false;
			}

			// Must contain at least one SVG element
			return $doc->getElementsByTagName('svg')->length > 0;
		} catch (\Exception) {
			return false;
		} finally {
			// Restore previous settings
			libxml_use_internal_errors($prevUseErrors);
			libxml_clear_errors();
		}
	}

	public static function sanitizeAndValidate(string $svg): string
	{
		if ($svg === '') {
			return '';
		}
		$sanitized = self::sanitize($svg);

		// Verify it's still valid SVG after sanitization
		if (!self::isValidSvg($sanitized)) {
			throw new \InvalidArgumentException('Invalid SVG content after sanitization');
		}

		return $sanitized;
	}

	/**
	 * Get or create SVG sanitizer instance.
	 */
	private static function getSanitizer(): Sanitizer
	{
		if (!self::$sanitizer instanceof Sanitizer) {
			self::$sanitizer = new Sanitizer();

			// Configure sanitizer for security
			self::$sanitizer->removeRemoteReferences(true);
			self::$sanitizer->minify(true); // Keep readable for debugging
			self::$sanitizer->removeXMLTag(true); // Remove XML declaration to match schema pattern
		}

		return self::$sanitizer;
	}
}
