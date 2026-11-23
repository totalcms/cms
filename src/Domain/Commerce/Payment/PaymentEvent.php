<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use TotalCMS\Domain\Money\Money;

/**
 * Something that happened to a payment, as the provider's webhook said it,
 * in the engine's own terms. The engine changes an order only on one of
 * these.
 */
final readonly class PaymentEvent implements ProviderEvent
{
	/**
	 * @param string               $eventId            the provider's event id
	 * @param string               $orderId            the engine's order id, from the provider's metadata. May be '' when
	 *                                                 $providerPaymentRef is set: a refund or dispute of a renewal payment
	 *                                                 carries no order id, and the order is found by that reference
	 * @param Money                $amount             the amount this event moves (the refund amount for a refund)
	 * @param string               $providerRef        the payment, refund or dispute id
	 * @param string               $providerPaymentRef the payment to refund against, on a paid event
	 * @param array<string,scalar> $summary            a few plain facts for the ledger; never card data
	 * @param string               $subscriptionRef    the provider's subscription id, on the first payment of a subscription
	 * @param string               $customerRef        the provider's customer id
	 */
	public function __construct(
		public string $provider,
		public string $eventId,
		public PaymentEventType $type,
		public string $orderId,
		public Money $amount,
		public string $providerRef,
		public string $providerPaymentRef = '',
		public array $summary = [],
		public string $subscriptionRef = '',
		public string $customerRef = '',
	) {
	}

	public function eventKey(): string
	{
		return $this->provider . ':' . $this->eventId;
	}
}
