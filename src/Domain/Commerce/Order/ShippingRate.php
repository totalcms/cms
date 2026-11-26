<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Money\Money;

/** One way an order can ship, and what it costs. */
final readonly class ShippingRate
{
	public function __construct(
		public string $id,
		public string $label,
		public Money $amount,
	) {
	}
}
