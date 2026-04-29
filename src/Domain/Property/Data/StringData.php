<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

use TotalCMS\Domain\Security\Sanitization\HTMLSanitizer;
use TotalCMS\Support\Config;

class StringData extends PropertyData implements \Stringable
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

		// Trim empty paragraphs from the beginning and end of HTML content
		if ($this->containsHTML()) {
			$this->text = $this->trimEmptyParagraphs($this->text);
		}

		// Apply text transform if configured (only on non-HTML plain text)
		$textTransform = (string)($this->settings['textTransform'] ?? '');
		if ($textTransform !== '' && !$this->containsHTML()) {
			$this->text = self::applyTextTransform($this->text, $textTransform);
		}
	}

	/**
	 * Apply a text transform to a string.
	 */
	private static function applyTextTransform(string $text, string $transform): string
	{
		return match ($transform) {
			'lowercase'    => mb_strtolower($text),
			'uppercase'    => mb_strtoupper($text),
			'titlecase'    => mb_convert_case($text, MB_CASE_TITLE),
			'sentencecase' => mb_strtoupper(mb_substr($text, 0, 1)) . mb_strtolower(mb_substr($text, 1)),
			default        => $text,
		};
	}

	/**
	 * Remove empty paragraphs from the beginning and end of HTML content.
	 */
	private function trimEmptyParagraphs(string $html): string
	{
		$pattern = '<p></p>';
		while (str_starts_with($html, $pattern)) {
			$html = substr($html, strlen($pattern));
		}
		while (str_ends_with($html, $pattern)) {
			$html = substr($html, 0, -strlen($pattern));
		}

		return trim($html);
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
