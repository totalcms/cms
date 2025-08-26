<?php

namespace TotalCMS\Domain\Twig\Extension;

use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Token parser for the {% cmsgrid %} Twig tag.
 *
 * Syntax:
 * {% cmsgrid objects from 'collection' with 'classes' as 'tag' %}
 *   template content with {{ object }} and {{ collection }} variables
 * {% endcmsgrid %}
 */
class CmsGridTokenParser extends AbstractTokenParser
{
	public function parse(Token $token): Node
	{
		$lineno = $token->getLine();
		$stream = $this->parser->getStream();

		// Parse: cmsgrid objects
		$objects = $this->parser->parseExpression();

		// Parse: from 'collection'
		$collection = null;
		if ($stream->nextIf(Token::NAME_TYPE, 'from')) {
			$collection = $this->parser->parseExpression();
		}

		// Parse: with 'classes'
		$classes = null;
		if ($stream->nextIf(Token::NAME_TYPE, 'with')) {
			$classes = $this->parser->parseExpression();
		}

		// Parse: as 'tag'
		$itemTag = null;
		if ($stream->nextIf(Token::NAME_TYPE, 'as')) {
			$itemTag = $this->parser->parseExpression();
		}

		$stream->expect(Token::BLOCK_END_TYPE);

		// Parse the template content until {% endcmsgrid %}
		$template = $this->parser->subparse($this->decideBlockEnd(...), true);

		$stream->expect(Token::BLOCK_END_TYPE);

		return new CmsGridNode($objects, $collection, $classes, $itemTag, $template, $lineno);
	}

	public function decideBlockEnd(Token $token): bool
	{
		return $token->test('endcmsgrid');
	}

	public function getTag(): string
	{
		return 'cmsgrid';
	}
}
