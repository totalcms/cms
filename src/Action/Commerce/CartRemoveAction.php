<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Cart\CartService;

/**
 * Take a line out of the cart.
 */
final readonly class CartRemoveAction
{
	public function __construct(
		private CartService $cart,
		private CartResponder $responder,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->responder->change($request, $response, fn (array $body) => $this->cart->remove($this->responder->text($body, 'lineKey')));
	}
}
