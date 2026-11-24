<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use TotalCMS\Domain\Money\Money;

/**
 * Something that happened to a subscription, as the provider's webhook said
 * it. The engine changes a subscription only on one of these.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a value object: every
 *                                                  field is one fact from the
 *                                                  provider, named
 */
final readonly class SubscriptionEvent implements ProviderEvent
{
	/**
	 * @param string               $subscriptionId     the engine's id, from the provider's metadata
	 * @param string               $status             trialing | active | past_due | paused | canceled
	 * @param int|null             $currentPeriodEnd   unix time
	 * @param Money|null           $amount             Renewed: what was paid
	 * @param string               $providerInvoiceRef Renewed, PaymentFailed: the provider's invoice id
	 * @param string               $providerPaymentRef Renewed: the payment to refund against
	 * @param array<string,scalar> $summary
	 * @param Money|null           $tax                Renewed: the tax the provider worked out itself, when the store left it to the provider; part of $amount
	 * @param Money|null           $orderTotal         with $tax: what the period costs before that tax, which the renewal is checked against
	 */
	public function __construct(
		public string $provider,
		public string $eventId,
		public SubscriptionEventType $type,
		public string $subscriptionId,
		public string $providerSubscriptionRef,
		public string $status = '',
		public ?int $currentPeriodEnd = null,
		public bool $cancelAtPeriodEnd = false,
		public ?Money $amount = null,
		public string $providerInvoiceRef = '',
		public string $providerPaymentRef = '',
		public array $summary = [],
		public ?Money $tax = null,
		public ?Money $orderTotal = null,
	) {
	}

	public function eventKey(): string
	{
		return $this->provider . ':' . $this->eventId;
	}
}
