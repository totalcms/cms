<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * Something that must be done because an order changed. Each value is the
 * name of the flag that records it as done, so a retry does not do it again.
 */
enum SideEffect: string
{
	case Inventory         = 'inventoryApplied';
	case Downloads         = 'downloadsIssued';
	case Receipt           = 'receiptQueued';
	case DispatchPaid      = 'paidDispatched';
	case DispatchRefunded  = 'refunded';
	case DispatchDisputed  = 'disputed';
	case DispatchFulfilled = 'fulfilled';
	case AdminNotice       = 'adminNotice';
}
