<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Domain\Twig\Markdown\TiptapToMarkdownConverter;

/**
 * Per-field-value renderer for content tools' `format` parameter.
 *
 * Content tools (`query_collection`, `get_object`, `search_collection`,
 * `search_collections`) accept a `format` arg that the agent uses to pick how
 * `styledtext` properties come back. The caller (content tool) decides WHICH
 * properties to render — this service decides HOW.
 *
 * **Routes:**
 *   - `markdown` (default) — HTML → markdown via TiptapToMarkdownConverter.
 *   - `html` — pass-through (input is already HTML).
 *   - `text` — strip tags + decode entities → plain text.
 *
 * **Input polymorphism.** Accepts either a plain HTML string (styledtext) or
 * a locale-keyed object (`{en_US: "<html>", de: "<html>", ...}` —
 * localizedstyledtext). For the locale-keyed shape, each locale's HTML is
 * rendered independently and the keys preserved; non-string locale values
 * pass through verbatim so a malformed locale entry doesn't crash the
 * pipeline.
 *
 * Unknown format strings fall back to markdown silently — defensive default
 * vs. erroring on a typo'd format that the agent then can't recover from.
 */
readonly class ContentRenderer
{
	public function __construct(
		private TiptapToMarkdownConverter $converter,
	) {
	}

	public function render(mixed $value, string $format = 'markdown'): mixed
	{
		if (is_string($value)) {
			return $this->renderHtmlString($value, $format);
		}

		if (is_array($value)) {
			$out = [];
			foreach ($value as $locale => $localeValue) {
				$out[$locale] = is_string($localeValue)
					? $this->renderHtmlString($localeValue, $format)
					: $localeValue;
			}

			return $out;
		}

		return $value;
	}

	private function renderHtmlString(string $html, string $format): string
	{
		if ($html === '') {
			return '';
		}

		return match ($format) {
			'html' => $html,
			'text' => $this->toPlainText($html),
			default => $this->converter->convert($html),
		};
	}

	private function toPlainText(string $html): string
	{
		// Insert newlines between block-level tags before stripping, so
		// "<p>First</p><p>Second</p>" doesn't collapse to "FirstSecond".
		// Targeted at the closing tags of common block elements rather than
		// trying to enumerate every possible HTML block tag — covers the
		// realistic styledtext cases without over-engineering.
		$separated = preg_replace(
			'#</(?:p|div|h[1-6]|li|blockquote|tr)>#i',
			"$0\n",
			$html,
		) ?? $html;

		$stripped = strip_tags($separated);

		return html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}
