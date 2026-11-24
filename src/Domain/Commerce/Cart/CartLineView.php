<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

use TotalCMS\Domain\Money\Money;

/**
 * One line of a cart, priced from the catalog for the buyer looking at it.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a value object: every
 *                                                  field is one fact of the
 *                                                  line, named
 */
final readonly class CartLineView
{
	/**
	 * @param string $variantTitle '' for a product without variants
	 * @param string $image        the product's main image URL, or ''
	 * @param string $priceSource  `catalog`, or the id of the price resolver that set the unit price
	 * @param int    $discountTotal this line's share of the cart's discount code, in minor units
	 * @param string $billing       `once`, or `recurring` for a plan
	 * @param string $interval      for a plan: day, week, month or year
	 * @param int    $intervalCount for a plan: billed every this many intervals
	 * @param int    $trialDays     for a plan: free days before the first payment
	 */
	public function __construct(
		public string $lineKey,
		public string $productId,
		public string $variantId,
		public string $title,
		public string $variantTitle,
		public string $image,
		public int $qty,
		public Money $unitPrice,
		public Money $lineTotal,
		public string $priceSource,
		public int $discountTotal = 0,
		public string $billing = 'once',
		public string $interval = '',
		public int $intervalCount = 0,
		public int $trialDays = 0,
	) {
	}

	public function withDiscount(int $discountTotal): self
	{
		return new self($this->lineKey, $this->productId, $this->variantId, $this->title, $this->variantTitle, $this->image, $this->qty, $this->unitPrice, $this->lineTotal, $this->priceSource, $discountTotal, $this->billing, $this->interval, $this->intervalCount, $this->trialDays);
	}
}
