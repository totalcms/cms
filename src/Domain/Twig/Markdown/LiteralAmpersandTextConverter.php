<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Markdown;

use League\HTMLToMarkdown\Converter\TextConverter;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Text nodes with a plain `&` left as `&`.
 *
 * The library HTML-escapes every text node, so "Tom & Jerry" comes out as
 * `Tom &amp; Jerry`. That is valid Markdown — it renders as an ampersand —
 * but the reader here is an agent taking the text at face value, and it
 * copies `&amp;` into titles and summaries.
 *
 * `&amp;` is turned back into `&` wherever a bare ampersand means the same
 * thing in Markdown, which is everywhere it does not start an entity. Text
 * that literally reads `&lt;` or `&copy;` keeps its escaped ampersand
 * (`&amp;lt;`), or it would turn into a different character. `<` and `>` stay
 * escaped: unknown tags are passed through as HTML (`strip_tags` is off), and
 * a literal `<b>` in the text must not read as one of them.
 */
final class LiteralAmpersandTextConverter extends TextConverter
{
	public function convert(ElementInterface $element): string
	{
		return (string)preg_replace(
			'/&amp;(?!(?:#\d+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);)/',
			'&',
			parent::convert($element),
		);
	}
}
