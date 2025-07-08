<?php

namespace TotalCMS\Domain\Twig\Extension;

use Twig\Token;
use Twig\Node\Node;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Token parser for the {% cmsgrid %} Twig tag
 * 
 * Syntax:
 * {% cmsgrid objects with 'classes' as 'tag' %}
 *   template content with {{ item }} variable
 * {% endcmsgrid %}
 */
final class CmsGridTokenParser extends AbstractTokenParser
{
	public function parse(Token $token): Node
	{
		$lineno = $token->getLine();
		$stream = $this->parser->getStream();
		
		// Parse: cmsgrid objects
		$objects = $this->parser->parseExpression();
		
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
		$template = $this->parser->subparse([$this, 'decideBlockEnd'], true);
		
		$stream->expect(Token::BLOCK_END_TYPE);
		
		return new CmsGridNode($objects, $classes, $itemTag, $template, $lineno, $this->getTag());
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