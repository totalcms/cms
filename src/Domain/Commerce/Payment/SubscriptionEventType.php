<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

enum SubscriptionEventType: string
{
	/** A later period was paid. */
	case Renewed = 'renewed';

	/** A renewal charge failed; the provider will retry. */
	case PaymentFailed = 'payment_failed';

	/** Status, period end or cancel-at-period-end changed. */
	case Updated = 'updated';

	/** Ended for good. */
	case Canceled = 'canceled';
}
