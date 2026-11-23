<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

/**
 * What a provider can do. `subscriptions` is true only for a
 * {@see SubscriptionProvider}, and `embedded` only for an
 * {@see EmbeddedCheckoutProvider}.
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
	) {
	}
}
