<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

use TotalCMS\Domain\Money\Money;

/**
 * A cart as its buyer sees it now.
 */
final readonly class CartView
{
	/**
	 * @param list<CartLineView> $lines
	 * @param int                $count    how many items, counting quantities
	 * @param Money              $subtotal the lines added up, before discount, shipping and tax
	 * @param list<string>       $notices  what changed since the buyer last looked, such as a product that was removed
	 */
	public function __construct(
		public array $lines,
		public int $count,
		public Money $subtotal,
		public array $notices,
	) {
	}

	public function isEmpty(): bool
	{
		return $this->lines === [];
	}
}
