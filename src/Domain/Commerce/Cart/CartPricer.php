<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Payment\Recurrence;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Commerce\Discount\DiscountLine;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Commerce\Pricing\PricingService;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\Money\Money;

/**
 * Prices a cart's lines from the catalog, now. A cart holds ids and
 * quantities, never a price, so this runs every time a cart is shown.
 *
 * A line whose product can no longer be bought is left out, with a notice
 * for the buyer. Nothing here writes: the caller decides what to keep.
 */
final readonly class CartPricer
{
	public function __construct(
		private ProductCatalog $catalog,
		private PricingService $pricing,
	) {
	}

	/**
	 * @param array<string,CartItem> $items the cart's lines, by line key
	 *
	 * @return array{lines: list<CartLineView>, discountLines: list<DiscountLine>, items: array<string,CartItem>, subtotal: Money, count: int, notices: list<string>}
	 *                                                                                                                                                           `items` is what was given, less what can no longer be bought
	 */
	public function price(array $items, PricingContext $ctx): array
	{
		$lines         = [];
		$discountLines = [];
		$notices       = [];
		$subtotal      = Money::zero($ctx->currency);
		$count         = 0;

		foreach ($items as $key => $item) {
			$product = $this->catalog->product($item->productId);
			if (!$product instanceof ProductView || !$this->sellable($product, $item->variantId)) {
				$notices[] = $this->goneNotice($product);
				unset($items[$key]);

				continue;
			}

			$variant = $product->variants[$item->variantId] ?? null;
			$unit    = $this->pricing->unitPrice($product, $variant, $item->qty, $ctx);
			$total   = $unit->unitPrice->multiply($item->qty);

			$every           = $this->recurrence($product, $variant);
			$lines[]         = new CartLineView($key, $product->id, $item->variantId, $product->title, $variant->title ?? '', $product->image, $item->qty, $unit->unitPrice, $total, $unit->source, 0, $product->billing, $every->interval ?? '', $every->intervalCount ?? 0, $every->trialDays ?? 0);
			$discountLines[] = new DiscountLine($key, $product->id, $product->category, $total->amount(), false, false);
			$subtotal        = $subtotal->add($total);
			$count += $item->qty;
		}

		return ['lines' => $lines, 'discountLines' => $discountLines, 'items' => $items, 'subtotal' => $subtotal, 'count' => $count, 'notices' => $notices];
	}

	/** How often a line is billed; null for one paid for once, or a plan whose period cannot be billed. */
	private function recurrence(ProductView $product, ?VariantView $variant): ?Recurrence
	{
		try {
			return $product->recurrence($variant);
		} catch (CommerceException) {
			return null;
		}
	}

	private function sellable(ProductView $product, string $variantId): bool
	{
		if (!$product->isActive()) {
			return false;
		}
		if (!$product->hasVariants) {
			return $variantId === '';
		}

		return isset($product->variants[$variantId]) && $product->variants[$variantId]->active;
	}

	private function goneNotice(?ProductView $product): string
	{
		return $product instanceof ProductView && $product->title !== ''
			? "{$product->title} is no longer available and was removed from your cart."
			: 'An item is no longer available and was removed from your cart.';
	}
}
