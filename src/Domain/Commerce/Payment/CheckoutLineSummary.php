<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use TotalCMS\Domain\Money\Money;

/**
 * One line of an order, as the provider's payment page shows it.
 */
final readonly class CheckoutLineSummary
{
	/**
	 * @param Money           $discount          this line's discount on the first payment (zero = none)
	 * @param Money           $recurringDiscount this line's discount on every later payment (zero unless the code repeats)
	 * @param Recurrence|null $recurrence        null = paid once
	 * @param bool            $taxable           false for a product the store does not tax; for a provider that works the tax out itself
	 */
	public function __construct(
		public string $title,
		public int $qty,
		public Money $unitAmount,
		public Money $discount,
		public Money $recurringDiscount,
		public ?Recurrence $recurrence = null,
		public bool $taxable = true,
	) {
	}
}
