<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Order\OrderAdmin;

/**
 * POST /admin/commerce/orders/{id}/fulfill: mark lines as sent.
 *
 * Body: `lines[]` (line ids), and for the parcel `trackingNumber`,
 * `trackingUrl` and `note`, each optional.
 */
final readonly class AdminOrderFulfillAction
{
	public function __construct(
		private OrderActionResponder $responder,
		private OrderAdmin $admin,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$body  = (array)$request->getParsedBody();
		$lines = array_values(array_filter(is_array($body['lines'] ?? null) ? $body['lines'] : [], static fn (mixed $id): bool => is_string($id) && $id !== ''));
		$text  = static fn (string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';

		return $this->responder->run($request, $response, $args['id'] ?? '', function (string $id) use ($lines, $text): string {
			$order = $this->admin->fulfill($id, $lines, $text('trackingNumber'), $text('trackingUrl'), $text('note'));

			return $order->fulfillmentStatus() === 'fulfilled' ? 'Marked as sent. This order is fulfilled.' : 'Marked as sent.';
		});
	}
}
