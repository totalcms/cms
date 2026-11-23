<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * An order the server has built and priced, not yet stored or paid.
 */
final readonly class OrderDraft
{
	/**
	 * @param array<string,OrderLineDraft>     $lines      keyed l1, l2, … in cart order
	 * @param array<string,string>             $attributes the checkout attributes the settings allow
	 * @param array<string,mixed>              $metadata   written by order-building listeners
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

		return new self($this->currency, $this->email, $updated, $this->totals, $this->shippingAddress, $this->billingAddress, $this->attributes, $order, $this->customerCollection, $this->customerId);
	}
}
