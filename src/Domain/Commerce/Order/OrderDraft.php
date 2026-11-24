<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Subscription\SubscriptionDraft;

/**
 * An order the server has built and priced, not yet stored or paid.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a value object: every field is one fact about the order, named
 */
final readonly class OrderDraft
{
	/**
	 * @param array<string,OrderLineDraft>     $lines      keyed l1, l2, … in cart order
	 * @param array<string,string>             $attributes the checkout attributes the settings allow
	 * @param array<string,mixed>              $metadata   written by order-building listeners
	 * @param SubscriptionDraft|null           $subscription the part of the order that is billed again every period; null when nothing in it is
	 */
	public function __construct(
		public string $currency,
		public string $email,
		public array $lines,
		public Totals $totals,
		public ?Address $shippingAddress,
		public ?Address $billingAddress,
		public array $attributes,
		public array $metadata = [],
		public string $customerCollection = '',
		public string $customerId = '',
		public ?SubscriptionDraft $subscription = null,
	) {
	}

	/** Whether anything in the order has to be sent. */
	public function ships(): bool
	{
		return array_filter($this->lines, static fn (OrderLineDraft $line): bool => $line->ships()) !== [];
	}

	public function hasRecurringLine(): bool
	{
		return array_filter($this->lines, static fn (OrderLineDraft $line): bool => $line->isRecurring()) !== [];
	}

	/**
	 * The same order with this metadata. Money is not touched.
	 *
	 * @param array<string,mixed>               $order
	 * @param array<string,array<string,mixed>> $lines line id => that line's metadata
	 */
	public function withMetadata(array $order, array $lines): self
	{
		$updated = [];
		foreach ($this->lines as $id => $line) {
			$updated[$id] = isset($lines[$id]) ? $line->withMetadata($lines[$id]) : $line;
		}

		return new self($this->currency, $this->email, $updated, $this->totals, $this->shippingAddress, $this->billingAddress, $this->attributes, $order, $this->customerCollection, $this->customerId, $this->subscription);
	}

	/** The same order with its tax left to the payment provider: none of the store's own on it. */
	public function leftToProvider(): self
	{
		$untaxed = static fn (OrderLineDraft $line): OrderLineDraft => $line->withTax(0);
		// What is billed each period is left to the provider as well.
		$again   = $this->subscription instanceof SubscriptionDraft
			? new SubscriptionDraft($this->subscription->recurrence, array_map($untaxed, $this->subscription->lines), $this->subscription->totals->leftToProvider(), $this->subscription->discountCode)
			: null;

		return new self($this->currency, $this->email, array_map($untaxed, $this->lines), $this->totals->leftToProvider(), $this->shippingAddress, $this->billingAddress, $this->attributes, $this->metadata, $this->customerCollection, $this->customerId, $again);
	}
}
