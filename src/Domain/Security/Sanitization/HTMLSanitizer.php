<?php

namespace TotalCMS\Domain\Security\Sanitization;

/**
 * HTML Sanitizer for preventing XSS attacks while preserving safe HTML.
 */
class HTMLSanitizer
{
	/**
	 * @param array<string,mixed> $config
	 */
	public static function sanitizeRichContent(string $html, array $config = []): string
	{
		if ($html === '') {
			return '';
		}

		$html = self::removeScripts($html);
		$html = self::removeEventHandlers($html);
		$html = self::removeDangerousProtocols($html);
		$html = self::removeDangerousTags($html);
		$html = self::handleIframes($html, $config);
		$html = self::sanitizeStyles($html, $config);

		return self::handleAllowedTags($html, $config);
	}

	/**
	 * @param array<string,mixed> $config
	 */
	public static function sanitizeStrictContent(string $html, array $config = []): string
	{
		// Strict mode removes all styles and more tags
		$html = self::sanitizeRichContent($html, ['allowed_css_properties' => []]);

		// Remove additional tags not allowed in strict mode
		$restrictedTags = ['div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
		foreach ($restrictedTags as $tag) {
			$html = (string)preg_replace("/<\/?{$tag}[^>]*>/i", '', $html);
		}

		return $html;
	}

	private static function removeScripts(string $html): string
	{
		return (string)preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
	}

	private static function removeEventHandlers(string $html): string
	{
		// Remove event handlers with double quotes
		$html = (string)preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $html);
		// Remove event handlers with single quotes
		$html = (string)preg_replace('/\s+on\w+\s*=\s*\'[^\']*\'/i', '', $html);
		// Remove event handlers without quotes - but be more specific to avoid matching URLs
		// Only match when preceded by whitespace and followed by whitespace or tag closing
		$html = (string)preg_replace('/\s+on\w+\s*=\s*[^"\'\s>]+(?=\s|>)/i', '', $html);

		return $html;
	}

	private static function removeDangerousProtocols(string $html): string
	{
		// Only strip protocols inside tag markup (attribute values, etc.).
		// Text content between tags is left alone so prose and code samples
		// can mention `javascript:` / `vbscript:` literally without damage.
		return (string)preg_replace_callback(
			'/<[^>]*>/',
			static function (array $m): string {
				$tag = (string)preg_replace('/javascript:/i', '', $m[0]);
				$tag = (string)preg_replace('/vbscript:/i', '', $tag);

				return (string)preg_replace('/data:text\/html/i', '', $tag);
			},
			$html
		);
	}

	private static function removeDangerousTags(string $html): string
	{
		$dangerousTags = ['object', 'embed', 'form', 'input', 'link', 'meta', 'base', 'style'];
		foreach ($dangerousTags as $tag) {
			$html = (string)preg_replace("/<{$tag}[^>]*>.*?<\/{$tag}>/is", '', $html);
			$html = (string)preg_replace("/<{$tag}[^>]*\/?>/i", '', $html);
		}

		return $html;
	}

	/**
	 * @param array<string,mixed> $config
	 */
	private static function handleIframes(string $html, array $config): string
	{
		if (!empty($config['allowed_iframe_domains'] ?? [])) {
			return self::processCustomIframeDomains($html, $config['allowed_iframe_domains']);
		}

		return self::processDefaultIframes($html);
	}

	/**
	 * @param array<string> $allowedDomains
	 */
	private static function processCustomIframeDomains(string $html, array $allowedDomains): string
	{
		$domainPattern = '(' . implode('|', array_map(preg_quote(...), $allowedDomains)) . ')';

		// Keep iframes from allowed domains
		preg_match_all('/<iframe[^>]*>.*?<\/iframe>/is', $html, $iframes);
		$html = (string)preg_replace('/<iframe[^>]*>.*?<\/iframe>/is', '', $html); // Remove all first

		foreach ($iframes[0] as $iframe) {
			if (preg_match('/src=["\']https?:\/\/' . $domainPattern . '/i', $iframe)) {
				$html .= $iframe; // Add back allowed iframes
			}
		}

		return $html;
	}

	private static function processDefaultIframes(string $html): string
	{
		return self::processCustomIframeDomains($html, HTMLSanitizerConfig::ALLOWED_IFRAME_DOMAINS);
	}

	/**
	 * @param array<string,mixed> $config
	 */
	private static function sanitizeStyles(string $html, array $config): string
	{
		// Strip `expression(...)` — historic IE-only XSS vector. Anchored on the
		// function-call shape so the literal word "expression" inside a font
		// name or CSS comment isn't a false positive. Other protocols inside
		// style values (javascript:/vbscript:/data:text/html) are already
		// removed by removeDangerousProtocols, which runs earlier.
		$html = (string)preg_replace('/\s*style\s*=\s*["\'][^"\']*expression\s*\([^"\']*["\']/i', '', $html);

		if (empty($config['allowed_css_properties'] ?? [])) {
			$html = (string)preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/i', '', $html);
		}

		return $html;
	}

	/**
	 * @param array<string,mixed> $config
	 */
	private static function handleAllowedTags(string $html, array $config): string
	{
		if (!isset($config['allowed_tags']) || !is_array($config['allowed_tags'])) {
			return $html;
		}

		$allowedTags = array_map(strtolower(...), array_filter($config['allowed_tags'], is_string(...)));

		return (string)preg_replace_callback(
			'/<\/?([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/',
			static fn (array $m): string => in_array(strtolower($m[1]), $allowedTags, true) ? $m[0] : '',
			$html
		);
	}
}
