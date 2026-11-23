<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/** Stock is not counted yet: a paid order changes none. */
final readonly class NullInventoryApplier implements InventoryApplier
{
	public function apply(OrderData $order): OrderData
	{
		return $order;
	}
}
