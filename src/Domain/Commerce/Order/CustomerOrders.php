<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Service\CurrentCustomer;

/**
 * The orders a visitor may see: the one a signed link opens, and, for a
 * signed-in customer, their own. Each comes back as its buyer may see it,
 * never with the order's secret.
 */
final readonly class CustomerOrders
{
	public function __construct(
		private OrderAccess $access,
		private OrderRepository $orders,
		private CurrentCustomer $customer,
	) {
	}

	/** The order a signed link opens, whole, for the store's own use: never handed to a template as it is. */
	public function opened(string $orderId, string $token): ?OrderData
	{
		return $this->access->verify($orderId, $token);
	}

	/** @return array<string,mixed>|null */
	public function byLink(string $orderId, string $token): ?array
	{
		return $this->access->verify($orderId, $token)?->forBuyer();
	}

	/**
	 * The signed-in customer's orders, newest first. None for a guest.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function mine(int $limit = 20): array
	{
		$customer = $this->customer->get();
		if ($customer === null) {
			return [];
		}

		return array_map(
			static fn (OrderData $order): array => $order->forBuyer(),
			$this->orders->forCustomer($customer['collection'], $customer['id'], $limit),
		);
	}
}
