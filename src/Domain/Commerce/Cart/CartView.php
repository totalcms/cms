<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

use TotalCMS\Domain\Commerce\Discount\AppliedDiscount;
use TotalCMS\Domain\Money\Money;

/**
 * A cart as its buyer sees it now.
 */
final readonly class CartView
{
	/** What the code takes off the lines. Zero without a code, and for a free-shipping code. */
	public Money $discountTotal;

	/** The subtotal less the discount, before shipping and tax. */
	public Money $total;

	/**
	 * @param list<CartLineView>   $lines
	 * @param int                  $count    how many items, counting quantities
	 * @param Money                $subtotal the lines added up, before discount, shipping and tax
	 * @param list<string>         $notices  what changed since the buyer last looked, such as a product that was removed
	 * @param AppliedDiscount|null $discount the cart's discount code, worked out against it now; null for none
	 */
	public function __construct(
		public array $lines,
		public int $count,
		public Money $subtotal,
		public array $notices,
		public ?AppliedDiscount $discount = null,
	) {
		$this->discountTotal = Money::of($discount->amount ?? 0, $subtotal->currency());
		$this->total         = $subtotal->subtract($this->discountTotal);
	}

	public function isEmpty(): bool
	{
		return $this->lines === [];
	}

	/** Whether the cart holds a plan: something billed again every period. */
	public function hasSubscription(): bool
	{
		return array_filter($this->lines, static fn (CartLineView $line): bool => $line->billing === 'recurring') !== [];
	}
}
