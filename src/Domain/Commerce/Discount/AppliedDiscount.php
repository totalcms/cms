<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Discount;

/**
 * A discount code worked out for one order: what it takes off, already
 * split across the lines it applies to.
 */
final readonly class AppliedDiscount
{
	/**
	 * @param string            $type                 the code's type, as stored
	 * @param string            $value                the code's value, as stored
	 * @param int               $amount               what it takes off the first payment, in minor units
	 * @param array<string,int> $lineAmounts          line id => what it takes off that line now
	 * @param array<string,int> $recurringLineAmounts line id => what it takes off that line on every later payment
	 * @param string            $recurring            how long it lasts on a subscription
	 */
	public function __construct(
		public string $code,
		public string $type,
		public string $value,
		public int $amount,
		public array $lineAmounts,
		public array $recurringLineAmounts,
		public bool $freeShipping,
		public string $recurring,
	) {
	}
}
