<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

/**
 * What a provider can do. `subscriptions` is true only for a
 * {@see SubscriptionProvider}, and `embedded` only for an
 * {@see EmbeddedCheckoutProvider}. `tax` is true for one that can work out
 * the tax on an order itself and add it when the buyer pays.
 * `recurringCustomAmounts` is true for one that can bill a plan at an amount
 * the buyer named at checkout, not one made ahead of time: a provider that
 * does not say so is assumed not to.
 */
final readonly class ProviderCapabilities
{
	public function __construct(
		public bool $refunds = true,
		public bool $partialRefunds = true,
		public bool $hosted = true,
		public bool $embedded = false,
		public bool $subscriptions = false,
		public bool $billingPortal = false,
		public bool $tax = false,
		public bool $recurringCustomAmounts = false,
	) {
	}
}
