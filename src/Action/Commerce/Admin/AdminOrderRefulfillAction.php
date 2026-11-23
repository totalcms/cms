<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Order\OrderAdmin;

/**
 * POST /admin/commerce/orders/{id}/refulfill: tell the `order.paid`
 * listeners again, after one of them failed to deliver. Super admins only.
 */
final readonly class AdminOrderRefulfillAction
{
	public function __construct(
		private OrderActionResponder $responder,
		private OrderAdmin $admin,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		return $this->responder->run($request, $response, $args['id'] ?? '', function (string $id): string {
			$this->admin->refulfill($id);

			return 'Fulfillment was run again.';
		});
	}
}
