<?php

namespace TotalCMS\Domain\Property\Data;

use TotalCMS\Support\Config;
use TotalCMS\Utils\HTMLSanitizer;

class StringData extends PropertyData
{
	private static ?HTMLSanitizer $sanitizer = null;

	public function __construct(public string $text = '', public array $settings = [])
	{
		// Sanitize HTML content unless explicitly disabled
		if ($this->containsHTML() && ($this->settings['htmlpurify'] ?? true) !== false) {
			// Use HTML sanitizer to clean the text
			$this->text = $this->getSanitizer()->sanitizeRichContent($this->text);
		}
	}

	public function transform(): string
	{
		return (string)$this;
	}

	public function __toString(): string
	{
		return $this->text;
	}

	public function containsHTML(): bool
	{
		return $this->text !== strip_tags($this->text);
	}

	/** Get HTML sanitizer instance (lazy loading) */
	private function getSanitizer(): HTMLSanitizer
	{
		if (self::$sanitizer === null) {
			$config   = Config::init();
			$settings = $config->htmlpurify;

			if (is_array($this->settings['htmlpurify'] ?? null)) {
				// Merge with Total CMS settings if available
				$settings = array_merge($settings, $this->settings['htmlpurify']);
			}

			// For now, use default configuration
			self::$sanitizer = new HTMLSanitizer($settings);
		}

		return self::$sanitizer;
	}
}
