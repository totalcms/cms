<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Money\Money;

/**
 * Works out how an order can ship. Asked only when something in it ships.
 */
interface ShippingCalculator
{
	/**
	 * The rates on offer, the default first. None means the order cannot ship there.
	 *
	 * @param array<string,OrderLineDraft> $lines
	 * @param Money                        $subtotal the lines' total after any discount, which a free-over threshold compares against
	 *
	 * @return list<ShippingRate>
	 */
	public function rates(array $lines, Money $subtotal, ?Address $address): array;
}
