<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Cart\CartService;

/**
 * POST /commerce/cart/discount: put a discount code on the cart.
 *
 * Only `code` is read from the request. What the code takes off is worked
 * out by the store, now and again at checkout. This is the one place a
 * visitor can guess codes, so it has a tight limit of its own.
 */
final readonly class CartDiscountAction
{
	public function __construct(
		private CartService $cart,
		private CartResponder $responder,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->responder->change($request, $response, fn (array $body) => $this->cart->applyDiscount(
			$this->responder->text($body, 'code'),
			$this->responder->context(),
		));
	}
}
