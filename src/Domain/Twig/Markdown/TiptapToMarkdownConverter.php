<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Markdown;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * Converts T3 `styledtext` field content to Markdown for AI consumption.
 *
 * **Input is HTML, not Tiptap JSON.** Tiptap is the editor used in the admin
 * UI, but it serializes to HTML for storage and the class operates on that
 * HTML. The "Tiptap" in the name flags that this is calibrated for the HTML
 * shapes T3's Tiptap editor produces (with its extensions: ImageUpload,
 * FileLink, VideoEmbed, RawHTML, InlineClass) rather than generic HTML
 * conversion.
 *
 * Wraps `league/html-to-markdown` with T3-friendly defaults:
 *   - ATX headings (`# Heading`) — shorter on the wire, easier for the agent
 *     to parse than the underline (setext) style.
 *   - Suppress libxml parse errors on malformed markup — customers occasionally
 *     have legacy content from migrations that doesn't validate.
 *   - Don't strip unknown tags — T3 Tiptap extensions may emit custom elements
 *     (FileLink, VideoEmbed, etc.) that the library doesn't natively
 *     understand. Preserving them is more honest than dropping content.
 *     Future: register `Converter` implementations for the T3-specific
 *     elements when their wire format stabilises.
 */
readonly class TiptapToMarkdownConverter
{
	private HtmlConverter $converter;

	public function __construct()
	{
		$this->converter = new HtmlConverter([
			'header_style'    => 'atx',
			'suppress_errors' => true,
			'strip_tags'      => false,
			'hard_break'      => true,  // <br> → \n (not the two-trailing-space form)
		]);
	}

	public function convert(string $html): string
	{
		if (trim($html) === '') {
			return '';
		}

		return $this->converter->convert($html);
	}
}
