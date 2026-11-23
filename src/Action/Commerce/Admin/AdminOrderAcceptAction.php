<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderAdmin;

/**
 * POST /admin/commerce/orders/{id}/accept: take the payment an order is
 * held for review with. The order becomes paid, and the buyer gets their
 * receipt and what they bought. Super admins only: it decides that money
 * which did not match the order is kept for it.
 */
final readonly class AdminOrderAcceptAction
{
	public function __construct(
		private OrderActionResponder $responder,
		private OrderAdmin $admin,
		private ActorContext $actors,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		return $this->responder->run($request, $response, $args['id'] ?? '', function (string $id): string {
			$this->admin->acceptPayment($id, $this->actors->current());

			return 'The payment is accepted. This order is paid.';
		});
	}
}
