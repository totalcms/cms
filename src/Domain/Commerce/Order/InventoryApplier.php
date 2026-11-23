<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * Takes what an order sold out of stock, once, when the order is paid.
 */
interface InventoryApplier
{
	/**
	 * Called with the order locked. Returns the order to save: the same one,
	 * or one flagged for staff when stock ran out (`needsAttention: oversold`).
	 */
	public function apply(OrderData $order): OrderData;
}
