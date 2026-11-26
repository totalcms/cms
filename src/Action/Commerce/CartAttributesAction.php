<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Cart\CartService;

/**
 * Keep a line of text the buyer gave, such as a gift note.
 */
final readonly class CartAttributesAction
{
	public function __construct(
		private CartService $cart,
		private CartResponder $responder,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->responder->change($request, $response, fn (array $body) => $this->cart->setAttribute(
			$this->responder->text($body, 'key'),
			$this->responder->text($body, 'value'),
		));
	}
}
