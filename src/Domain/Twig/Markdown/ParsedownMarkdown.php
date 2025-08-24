<?php

namespace TotalCMS\Domain\Twig\Markdown;

use Twig\Extra\Markdown\MarkdownInterface;

/**
 * Parsedown adapter for Twig Markdown extension.
 * Based on the CommonMark integration pattern from https://github.com/twigphp/Twig/pull/3737.
 */
final readonly class ParsedownMarkdown implements MarkdownInterface
{
	private \ParsedownExtra $parsedown;

	public function __construct()
	{
		$this->parsedown = new \ParsedownExtra();

		// Configure Parsedown for safety
		$this->parsedown->setSafeMode(true);
		$this->parsedown->setBreaksEnabled(true);
	}

	public function convert(string $body): string
	{
		return $this->parsedown->text($body);
	}
}
