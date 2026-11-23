<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Order\OrderAdmin;

/**
 * POST /admin/commerce/orders/{id}/receipt: send the buyer their receipt
 * again. It goes to the order's own email address.
 */
final readonly class AdminOrderReceiptAction
{
	public function __construct(
		private OrderActionResponder $responder,
		private OrderAdmin $admin,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		return $this->responder->run($request, $response, $args['id'] ?? '', fn (string $id): string => $this->admin->resendReceipt($id)
			? 'The receipt is on its way to the buyer.'
			: 'Nothing was sent: this store has no receipt email set up.');
	}
}
