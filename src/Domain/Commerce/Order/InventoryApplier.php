<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Payment\PaymentEvent;

/**
 * Takes what an order sold out of stock, once, when the order is paid,
 * and puts it back when a refund says the goods are coming back.
 */
interface InventoryApplier
{
	/**
	 * Called with the order locked. Returns the order to save: the same one,
	 * or one flagged for staff when stock ran out (`needsAttention: oversold`).
	 */
	public function apply(OrderData $order): OrderData;

	/**
	 * Called with the order locked, when a refund of it is confirmed.
	 * Returns the order to save: marked as restocked when the items went
	 * back, which they do once and only when staff asked.
	 */
	public function restock(OrderData $order, PaymentEvent $event): OrderData;
}
