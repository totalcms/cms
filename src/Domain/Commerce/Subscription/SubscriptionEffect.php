<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

/**
 * Something that must be done because a subscription changed.
 */
enum SubscriptionEffect: string
{
	/** Give or take away the access groups the subscriber's purchases back. */
	case SyncAccess = 'syncAccess';

	case DispatchCreated = 'createdDispatched';

	/** A period was paid for the right amount: make its order and pay it. */
	case RenewalOrder = 'renewalOrder';

	/** A period was paid for another amount: make its order, held for a person. */
	case RenewalReview = 'renewalReview';

	case DispatchRenewed = 'renewed';

	case PaymentFailedEmail = 'paymentFailedEmail';

	case DispatchPastDue = 'pastDue';

	case DispatchCanceled = 'canceledDispatched';
}
