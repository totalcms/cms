<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Cart\CartItem;
use TotalCMS\Domain\Commerce\Cart\CustomAmount;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Commerce\Discount\AppliedDiscount;
use TotalCMS\Domain\Commerce\Discount\DiscountService;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Payment\Recurrence;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Commerce\Pricing\PricingService;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionDraft;
use TotalCMS\Domain\Money\Money;

/**
 * Builds the order a buyer is about to pay for, on the server, from the
 * catalog. The cart says which products and how many; every price is worked
 * out again here, and the lines are a snapshot of the products as they were
 * sold. Nothing in the request sets an amount.
 */
final readonly class OrderBuilder
{
	public function __construct(
		private ProductCatalog $catalog,
		private PricingService $pricing,
		private TotalsCalculator $totals,
		private OrderBuildingRegistry $listeners,
		private DiscountService $discounts,
		private CommerceSettings $settings,
	) {
	}

	/**
	 * @param array<array-key,CartItem> $items        the cart's lines, in cart order
	 * @param string                    $discountCode the code on the cart, or '' for none. It is checked again
	 *                                                here, now with the buyer's email, and what it takes off is
	 *                                                worked out again: the cart never carries an amount
	 * @param bool                      $estimate     build it to show what it comes to, not to sell it: an order that
	 *                                                cannot ship yet is built without shipping and not refused
	 *
	 * @throws CommerceException when the cart is empty, a product can no longer be bought, the order cannot
	 *                           ship, or the code can no longer be used
	 */
	public function build(array $items, CheckoutInput $input, PricingContext $ctx, string $discountCode = '', bool $estimate = false): OrderDraft
	{
		if ($items === []) {
			throw CommerceException::cartEmpty();
		}

		$lines = [];
		foreach (array_values($items) as $position => $item) {
			$lineId         = 'l' . ($position + 1);
			$lines[$lineId] = $this->line($lineId, $item, $ctx);
		}

		$subtotal = array_sum(array_map(static fn (OrderLineDraft $line): int => $line->lineTotal, $lines));
		$discount = $discountCode === '' ? null : $this->discounts->resolve($discountCode, $lines, Money::of($subtotal, $ctx->currency), $input->email);

		$totals = $this->totals->calculate($lines, $input->shipping ?? $input->billing, $input->shippingRateId, $ctx->currency, $discount, $estimate);
		foreach ($lines as $lineId => $line) {
			$lines[$lineId] = $line->withDiscount($totals->lineDiscount[$lineId] ?? 0)->withTax($totals->lineTax[$lineId] ?? 0);
		}

		return $this->listeners->run(new OrderDraft(
			currency: $ctx->currency,
			email: $input->email,
			lines: $lines,
			totals: $totals,
			shippingAddress: $input->shipping,
			billingAddress: $input->billing,
			attributes: $input->attributes,
			customerCollection: (string)$ctx->customerCollection,
			customerId: (string)$ctx->customerId,
			subscription: $this->subscription($lines, $input, $ctx->currency, $discount),
		));
	}

	private function line(string $lineId, CartItem $item, PricingContext $ctx): OrderLineDraft
	{
		$product = $this->catalog->purchasable($item->productId, $item->variantId);
		$variant = $item->variantId === '' ? null : ($product->variants[$item->variantId] ?? null);
		// The same check the cart makes when it prices: an amount that stood when it went in may not now.
		if (!CustomAmount::stands($item, $product, $product->isRecurring($variant), $this->settings)) {
			throw CommerceException::productUnavailable();
		}
		$priced  = $this->pricing->unitPrice($product, $variant, $item->qty, $ctx, $item->amount);
		$every   = $product->recurrence($variant);

		return new OrderLineDraft(
			lineId: $lineId,
			productId: $product->id,
			variantId: $variant instanceof VariantView ? $variant->id : '',
			sku: $variant instanceof VariantView && $variant->sku !== '' ? $variant->sku : $product->sku,
			title: $this->title($product, $variant),
			type: $product->type,
			qty: $item->qty,
			unitPrice: $priced->unitPrice->amount(),
			listPrice: $priced->listPrice->amount(),
			priceSource: $priced->source,
			lineTotal: $priced->unitPrice->multiply($item->qty)->amount(),
			taxable: $product->taxable,
			requiresShipping: $product->requiresShipping,
			meta: $product->meta,
			category: $product->category,
			billing: $every instanceof Recurrence ? OrderLineDraft::RECURRING : OrderLineDraft::ONCE,
			interval: $every->interval ?? '',
			intervalCount: $every->intervalCount ?? 0,
			trialDays: $every->trialDays ?? 0,
			// Only a group on the store's allow-list is ever sold. Any other is
			// dropped here, so nothing later can give it.
			accessGroup: in_array($product->accessGroup, $this->settings->grantableGroups(), true) ? $product->accessGroup : '',
		);
	}

	/**
	 * The part of the order that is billed again every period: its
	 * recurring lines, added up on their own with the discount that repeats
	 * and the tax for the same address. Null when nothing in it recurs.
	 *
	 * @param array<string,OrderLineDraft> $lines
	 */
	private function subscription(array $lines, CheckoutInput $input, string $currency, ?AppliedDiscount $discount): ?SubscriptionDraft
	{
		$recurring = array_filter($lines, static fn (OrderLineDraft $line): bool => $line->isRecurring());
		if ($recurring === []) {
			return null;
		}

		// The same code, as it applies to every later payment.
		$again = $discount instanceof AppliedDiscount
			? new AppliedDiscount($discount->code, $discount->type, $discount->value, array_sum(array_intersect_key($discount->recurringLineAmounts, $recurring)), $discount->recurringLineAmounts, $discount->recurringLineAmounts, false, $discount->recurring)
			: null;
		$totals = $this->totals->calculate($recurring, $input->shipping ?? $input->billing, null, $currency, $again);

		$first = $recurring[array_key_first($recurring)];
		foreach ($recurring as $lineId => $line) {
			$recurring[$lineId] = $line->withDiscount($totals->lineDiscount[$lineId] ?? 0)->withTax($totals->lineTax[$lineId] ?? 0);
		}

		return new SubscriptionDraft(new Recurrence($first->interval, $first->intervalCount, $first->trialDays), $recurring, $totals, $again->code ?? '');
	}

	private function title(ProductView $product, ?VariantView $variant): string
	{
		return $variant instanceof VariantView && $variant->title !== '' ? "{$product->title} — {$variant->title}" : $product->title;
	}
}
