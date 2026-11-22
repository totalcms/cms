<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Cart\CartService;

/**
 * Set a cart line's quantity. 0 removes the line.
 */
final readonly class CartUpdateAction
{
	public function __construct(
		private CartService $cart,
		private CartResponder $responder,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->responder->change($request, $response, fn (array $body) => $this->cart->update(
			$this->responder->text($body, 'lineKey'),
			// No quantity is not a quantity of 0: it must not remove the line.
			$this->responder->qty($body, -1),
		));
	}
}
