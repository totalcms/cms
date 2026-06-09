<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Extension;

use Twig\Error\SyntaxError;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Token parser for {% cache key ttl=… tags=[…] shared=… %} … {% endcache %}.
 *
 * Only `key` is positional; ttl/tags/shared are optional named options in any
 * order. The block body is cached as rendered HTML by FragmentCache.
 */
final class CacheTokenParser extends AbstractTokenParser
{
	public function parse(Token $token): Node
	{
		$lineno = $token->getLine();
		$stream = $this->parser->getStream();

		$key = $this->parser->parseExpression();

		$ttl    = null;
		$tags   = null;
		$shared = null;

		while (!$stream->test(Token::BLOCK_END_TYPE)) {
			$name = $stream->expect(Token::NAME_TYPE)->getValue();
			$stream->expect(Token::OPERATOR_TYPE, '=');
			$value = $this->parser->parseExpression();

			match ($name) {
				'ttl'    => $ttl    = $value,
				'tags'   => $tags   = $value,
				'shared' => $shared = $value,
				default  => throw new SyntaxError(
					sprintf('Unknown option "%s" in "cache" tag. Expected ttl, tags, or shared.', $name),
					$token->getLine(),
					$stream->getSourceContext(),
				),
			};
		}

		$stream->expect(Token::BLOCK_END_TYPE);
		$body = $this->parser->subparse($this->decideEnd(...), true);
		$stream->expect(Token::BLOCK_END_TYPE);

		return new CacheNode($key, $ttl, $tags, $shared, $body, $lineno);
	}

	public function decideEnd(Token $token): bool
	{
		return $token->test('endcache');
	}

	public function getTag(): string
	{
		return 'cache';
	}
}
