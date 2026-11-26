<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Commerce\Order\OrderDraft;
use TotalCMS\Domain\Commerce\Order\TaxCalculator;

/**
 * What an order would come to, to show a buyer before they commit to it.
 * Amounts are in the currency's minor units. It is a figure to look at:
 * the order itself is built again, from the cart, when the buyer checks out.
 */
final readonly class CheckoutQuote
{
	/**
	 * @param bool   $ships         whether anything in the cart is sent
	 * @param bool   $shippingKnown false while the store cannot say what shipping costs: no address yet, or one it does not ship to
	 * @param bool   $taxIncluded   the tax is part of the prices, not added to the total
	 * @param bool   $taxAtPayment  the payment provider works the tax out, when the buyer pays
	 * @param bool   $taxPending    the store charges tax by its own rates and does not know where the buyer is yet
	 * @param string $problem       why this order cannot be checked out as it stands, in the buyer's words; '' when it can
	 * @param int    $recurringTotal for a cart with a plan: what is charged every period after the first payment; 0 otherwise
	 * @param string $interval      for a cart with a plan: day, week, month or year
	 * @param int    $intervalCount for a cart with a plan: every this many intervals
	 *
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a value object: every field is one line of the totals a buyer is shown
	 */
	public function __construct(
		public string $currency,
		public int $subtotal,
		public int $discount,
		public string $discountCode,
		public bool $ships,
		public int $shipping,
		public string $shippingLabel,
		public bool $shippingKnown,
		public int $tax,
		public string $taxLabel,
		public bool $taxIncluded,
		public bool $taxAtPayment,
		public bool $taxPending,
		public int $total,
		public string $problem = '',
		public int $recurringTotal = 0,
		public string $interval = '',
		public int $intervalCount = 0,
	) {
	}

	/**
	 * @param bool $knowsWhere whether the buyer has said which country they are in
	 */
	public static function of(OrderDraft $draft, bool $storeTaxes, bool $knowsWhere, string $problem = ''): self
	{
		$totals = $draft->totals;
		$label  = $totals->taxLines[0]['label'] ?? '';

		return new self(
			currency: $draft->currency,
			subtotal: $totals->subtotal,
			discount: $totals->discountTotal,
			discountCode: $totals->discountLine['code'] ?? '',
			ships: $draft->ships(),
			shipping: $totals->shippingTotal,
			shippingLabel: $totals->shippingLine['label'] ?? '',
			shippingKnown: $totals->shippingKnown,
			tax: $totals->taxTotal,
			taxLabel: $label !== '' ? $label : 'Tax',
			taxIncluded: $totals->taxMode === TaxCalculator::INCLUSIVE,
			taxAtPayment: $totals->taxMode === TaxCalculator::PROVIDER,
			taxPending: $storeTaxes && !$knowsWhere,
			total: $totals->grandTotal,
			problem: $problem,
			recurringTotal: $draft->subscription->totals->grandTotal ?? 0,
			interval: $draft->subscription->recurrence->interval ?? '',
			intervalCount: $draft->subscription->recurrence->intervalCount ?? 0,
		);
	}

	/** @return array<string,bool|int|string> */
	public function toArray(): array
	{
		return get_object_vars($this);
	}
}
