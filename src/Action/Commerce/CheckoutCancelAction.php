<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * GET /commerce/cancel/{orderId}?t=…: where the provider sends a buyer who
 * gave up. They go back to the page they checked out from, with their cart
 * as it was.
 *
 * The order is left alone. It is cancelled when they check out again, or
 * when the provider says the payment page expired.
 */
final readonly class CheckoutCancelAction
{
	public function __construct(
		private OrderPage $page,
		private SessionInterface $session,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$this->page->order($request, $args['orderId'] ?? '');

		// Set when this session started the checkout, and only ever to a page of this site.
		$from = $this->session->get(SessionKeys::COMMERCE_CHECKOUT_FROM);

		return $response->withStatus(303)->withHeader('Location', is_string($from) && $from !== '' ? $from : '/');
	}
}
