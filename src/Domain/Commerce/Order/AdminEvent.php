<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/** Something staff did to an order, put through the same rules as a provider's event. */
enum AdminEvent: string
{
	/** Cancel an order that has not been paid. */
	case Cancel = 'cancel';

	/** Take the payment an order was held for review with: the order is paid. */
	case AcceptPayment = 'accept_payment';

	/** One or more lines were marked fulfilled; work out what the order is now. */
	case LinesFulfilled = 'lines_fulfilled';
}
