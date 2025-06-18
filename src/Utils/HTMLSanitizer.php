<?php

namespace TotalCMS\Utils;

use HTMLPurifier;

/**
 * HTML sanitization utility for safe content storage and output.
 * Removes XSS vulnerabilities while preserving legitimate HTML content.
 * Supports configurable allowed tags and attributes.
 */
final class HTMLSanitizer
{
	private \HTMLPurifier $purifier;
	private \HTMLPurifier $strictPurifier;

	/**
	 * @param array<string,mixed> $userConfig Configuration overrides
	 */
	public function __construct(array $userConfig = [])
	{
		// Get rich content configuration
		$richConfig = HTMLSanitizerConfig::getConfig($userConfig);

		// Configure HTMLPurifier for rich content (CMS user content)
		$config = \HTMLPurifier_Config::createDefault();
		$config->set('Cache.SerializerPath', $richConfig['cache_path']);
		$config->set('HTML.Allowed', $richConfig['allowed_tags']);
		$config->set('CSS.AllowedProperties', $richConfig['allowed_css']);
		$config->set('AutoFormat.AutoParagraph', $richConfig['auto_paragraph']);
		$config->set('AutoFormat.RemoveEmpty', $richConfig['remove_empty']);

		// Configure safe iframes if domains are specified
		if (!empty($richConfig['safe_iframe_domains'])) {
			$config->set('HTML.SafeIframe', true);
			$config->set('URI.SafeIframeRegexp', HTMLSanitizerConfig::buildIframeRegex($richConfig['safe_iframe_domains']));
		}

		$this->purifier = new \HTMLPurifier($config);

		// Get strict configuration
		$strictConfigData = HTMLSanitizerConfig::getStrictConfig($userConfig);

		// Configure strict purifier for form inputs and user-generated content
		$strictConfig = \HTMLPurifier_Config::createDefault();
		$strictConfig->set('Cache.SerializerPath', $strictConfigData['cache_path']);
		$strictConfig->set('HTML.Allowed', $strictConfigData['allowed_tags']);
		$strictConfig->set('CSS.AllowedProperties', $strictConfigData['allowed_css']);
		$strictConfig->set('AutoFormat.AutoParagraph', $strictConfigData['auto_paragraph']);
		$strictConfig->set('AutoFormat.RemoveEmpty', $strictConfigData['remove_empty']);

		$this->strictPurifier = new \HTMLPurifier($strictConfig);
	}

	/**
	 * Sanitize rich HTML content for CMS storage.
	 * Allows most HTML tags but removes XSS vectors.
	 */
	public function sanitizeRichContent(string $html): string
	{
		return $this->purifier->purify($html);
	}

	/**
	 * Strictly sanitize user input allowing only basic formatting.
	 */
	public function sanitizeUserInput(string $html): string
	{
		return $this->strictPurifier->purify($html);
	}

	/**
	 * Check if content contains potentially dangerous HTML.
	 */
	public function containsDangerousContent(string $html): bool
	{
		$sanitized = $this->sanitizeRichContent($html);

		return $html !== $sanitized;
	}
}
