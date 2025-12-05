<?php

namespace TotalCMS\Domain\Property\Data;

use TotalCMS\Domain\Security\Sanitization\HTMLSanitizer;
use TotalCMS\Support\Config;

class CodeData extends PropertyData implements \Stringable
{
	public function __construct(public string $code = '', public array $settings = [])
	{
		// Sanitize HTML content unless explicitly disabled
		$config       = Config::init();
		// global is false by default for code fields
		$fieldEnabled = $this->settings['htmlclean'] ?? false;

		if ($this->containsHTML() && $fieldEnabled !== false) {
			// Use HTML sanitizer to clean the code
			$this->code = HTMLSanitizer::sanitizeRichContent($this->code, $config->htmlclean);
		}
	}

	public function transform(): string
	{
		return (string)$this;
	}

	public function __toString(): string
	{
		return $this->code;
	}

	public function containsHTML(): bool
	{
		return $this->code !== strip_tags($this->code);
	}
}
