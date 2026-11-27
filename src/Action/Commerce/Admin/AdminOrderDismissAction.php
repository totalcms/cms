<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderAdmin;

/**
 * POST /admin/commerce/orders/{id}/dismiss: mark an order that needs
 * someone as handled. It leaves the count in the sidebar, and keeps why it
 * was there and who dealt with it. No money moves and nothing is delivered,
 * so it is for anyone who may work on orders.
 */
final readonly class AdminOrderDismissAction
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
			$this->admin->dismiss($id, $this->actors->current());

			return 'Marked as handled. This order is no longer counted as needing attention.';
		});
	}
}
