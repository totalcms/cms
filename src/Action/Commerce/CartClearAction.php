<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Cart\CartService;

/**
 * Empty the cart.
 */
final readonly class CartClearAction
{
	public function __construct(
		private CartService $cart,
		private CartResponder $responder,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->responder->change($request, $response, fn () => $this->cart->clear());
	}
}
