<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Money\Money;

/**
 * Shipping that is not charged for: one rate, at nothing. The store ships,
 * so there is a rate; it costs the buyer nothing.
 */
final readonly class NoShippingCalculator implements ShippingCalculator
{
	public const RATE = 'none';

	public function rates(array $lines, Money $subtotal, ?Address $address): array
	{
		return [new ShippingRate(self::RATE, 'Shipping', Money::zero($subtotal->currency()))];
	}
}
