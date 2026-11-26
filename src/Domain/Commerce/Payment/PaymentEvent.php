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
	 * @param Money|null           $tax                on a paid event, the tax the provider worked out itself, when the store
	 *                                                 asked it to ({@see CheckoutRequest::$providerTax}); it is part of $amount.
	 *                                                 Null when the provider worked out none
	 * @param Money|null           $orderTotal         with $tax: the total the store asked for, as the provider has it. The
	 *                                                 order is checked against this, since $amount may hold tax on top of it
	 *
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a value object: every field is one fact about the payment, named
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
		public ?Money $tax = null,
		public ?Money $orderTotal = null,
	) {
	}

	public function eventKey(): string
	{
		return $this->provider . ':' . $this->eventId;
	}

	/**
	 * The tax the provider worked out for the order, in minor units. Null
	 * unless it sent the order's total with it: without that total there is
	 * nothing to check the order against, and the tax is not taken.
	 */
	public function taxForOrder(): ?int
	{
		return $this->orderTotal instanceof Money ? $this->tax?->amount() : null;
	}
}
