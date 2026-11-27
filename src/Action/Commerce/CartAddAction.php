<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Cart\CartService;

/**
 * Put a product in the cart.
 */
final readonly class CartAddAction
{
	public function __construct(
		private CartService $cart,
		private CartResponder $responder,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->responder->change($request, $response, function (array $body): void {
			// A JSON client may send the amount as a number; a form sends text.
			$sent   = $body['amount'] ?? null;
			$amount = is_float($sent) ? (string)$sent : $this->responder->text($body, 'amount');

			$this->cart->add(
				$this->responder->text($body, 'productId'),
				$this->responder->text($body, 'variantId'),
				$this->responder->qty($body, 1),
				$amount === '' ? null : $amount,
			);
		});
	}
}
