<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * Called while an order is being built, after it has been priced. A listener
 * may write metadata on the order and on its lines: who the seller of a line
 * is, which campaign the order came from. It cannot change a price.
 *
 * Any callable with this signature can be registered with
 * `$context->addOrderBuildingListener()`; implementing this interface is a
 * way to give one a class of its own.
 */
interface OrderBuildingHook
{
	public function __invoke(OrderDraftMetadata $meta, OrderDraft $draft): void;
}
