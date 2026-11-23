<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

/**
 * What a verified webhook becomes: something that happened to a payment
 * ({@see PaymentEvent}) or to a subscription ({@see SubscriptionEvent}).
 */
interface ProviderEvent
{
	/** "{provider}:{eventId}". A provider sends an event more than once; this is how a repeat is spotted. */
	public function eventKey(): string;
}
