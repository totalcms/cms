<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

use TotalCMS\Domain\Money\Money;

/**
 * One line of a cart, priced from the catalog for the buyer looking at it.
 */
final readonly class CartLineView
{
	/**
	 * @param string $variantTitle '' for a product without variants
	 * @param string $image        the product's main image URL, or ''
	 * @param string $priceSource  `catalog`, or the id of the price resolver that set the unit price
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
	) {
	}
}
