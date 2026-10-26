<?php

namespace TotalCMS\Domain\Twig\Markdown;

use Twig\Extra\Markdown\MarkdownInterface;

/**
 * Parsedown adapter for Twig Markdown extension.
 * Based on the CommonMark integration pattern from https://github.com/twigphp/Twig/pull/3737.
 *
 * Safe mode is on unless $allowHtml: HTML in the source is escaped and
 * unsafe link schemes (javascript:, data:…) are neutralized. With $allowHtml
 * the source's HTML passes through untouched, so only use it on content the
 * site's own editors wrote, never on visitor input.
 *
 * A titled image alone in its paragraph renders as a figure with the title as
 * its caption (see TotalParsedown).
 */
readonly class ParsedownMarkdown implements MarkdownInterface
{
	private TotalParsedown $parsedown;

	public function __construct(bool $allowHtml = false)
	{
		$this->parsedown = new TotalParsedown();

		$this->parsedown->setSafeMode(!$allowHtml);
		$this->parsedown->setBreaksEnabled(true);
	}

	public function convert(string $body): string
	{
		return $this->parsedown->text($body);
	}
}
