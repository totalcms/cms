<?php

namespace TotalCMS\Domain\Property\Data;

use TotalCMS\Support\Config;
use TotalCMS\Utils\HTMLSanitizer;

class StringData extends PropertyData
{
	public function __construct(public string $text = '', public array $settings = [])
	{
		// Sanitize HTML content unless explicitly disabled
		$config        = Config::init();
		$globalEnabled = $config->htmlclean['enabled'] ?? true;
		$fieldEnabled  = $this->settings['htmlclean'] ?? true;

		if ($this->containsHTML() && $globalEnabled && $fieldEnabled !== false) {
			// Use HTML sanitizer to clean the text
			$this->text = HTMLSanitizer::sanitizeRichContent($this->text, $config->htmlclean);
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
}
