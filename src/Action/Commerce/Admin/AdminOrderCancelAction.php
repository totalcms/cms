<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Order\OrderAdmin;

/**
 * POST /admin/commerce/orders/{id}/cancel: cancel an order that is still
 * waiting to be paid. Super admins only; a paid order is refunded instead.
 */
final readonly class AdminOrderCancelAction
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
			$this->admin->cancel($id);

			return 'This order is cancelled.';
		});
	}
}
