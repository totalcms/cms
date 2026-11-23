<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

/**
 * A provider that can bill a subscription.
 */
interface SubscriptionProvider extends PaymentProvider
{
	/**
	 * Ask the provider to cancel. MUST NOT change the subscription here: the webhook does.
	 *
	 * @throws ProviderException
	 */
	public function cancelSubscription(string $providerSubscriptionRef, bool $atPeriodEnd): void;

	/** Ask the provider where a subscription stands, for reconciliation. */
	public function fetchSubscription(string $providerSubscriptionRef): ?SubscriptionEvent;

	/** A page at the provider where the buyer updates the card and sees invoices. '' = none. */
	public function billingPortalUrl(string $providerCustomerRef, string $returnUrl): string;
}
