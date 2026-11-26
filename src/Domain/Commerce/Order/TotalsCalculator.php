<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Discount\AppliedDiscount;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Money\Money;

/**
 * Adds an order up, always in the same order: subtotal, discount, shipping,
 * tax, grand total. The cart a buyer is shown and the order that is charged
 * both come from here, so they cannot disagree.
 */
final readonly class TotalsCalculator
{
	public function __construct(
		private TaxCalculator $tax,
		private ShippingCalculator $shipping,
	) {
	}

	/**
	 * @param array<string,OrderLineDraft> $lines   keyed by line id
	 * @param Address|null                 $address where it ships; the billing address when nothing does
	 * @param bool                         $estimate for a figure to show, not an order: an order that cannot ship yet is
	 *                                               worked out without shipping ({@see Totals::$shippingKnown}) and not refused
	 *
	 * @throws CommerceException when the order ships and no rate covers it, or the chosen rate is not on offer
	 */
	public function calculate(array $lines, ?Address $address, ?string $shippingRateId, string $currency, ?AppliedDiscount $discount = null, bool $estimate = false): Totals
	{
		$subtotal     = array_sum(array_map(static fn (OrderLineDraft $line): int => $line->lineTotal, $lines));
		$lineDiscount = $this->lineDiscounts($lines, $discount);
		$discounted   = array_sum($lineDiscount);

		$rate     = $this->rate($lines, Money::of($subtotal - $discounted, $currency), $address, $shippingRateId, $estimate);
		$known    = $rate instanceof ShippingRate || array_filter($lines, static fn (OrderLineDraft $line): bool => $line->ships()) === [];
		$shipping = $rate?->amount->amount() ?? 0;
		$waived   = $discount?->freeShipping === true ? $shipping : 0;
		$shipping -= $waived;

		// Each line is taxed on what is charged for it, so the discount comes off first.
		$net = [];
		foreach ($lines as $id => $line) {
			$net[$id] = $line->withDiscount($lineDiscount[$id]);
		}
		$tax      = $this->tax->calculate($net, $shipping, $address, $currency);
		$lineTax  = array_map(intval(...), $tax['lines']) + array_fill_keys(array_keys($lines), 0);
		$taxTotal = array_sum($lineTax) + $tax['shipping'];
		$mode     = $this->tax->mode();

		return new Totals(
			subtotal: $subtotal,
			discountTotal: $discounted,
			shippingTotal: $shipping,
			taxTotal: $taxTotal,
			grandTotal: $subtotal - $discounted + $shipping + ($mode === TaxCalculator::EXCLUSIVE ? $taxTotal : 0),
			taxLines: $tax['taxLines'],
			shippingLine: $rate instanceof ShippingRate ? ['rateId' => $rate->id, 'label' => $rate->label, 'amount' => $shipping] : null,
			lineTax: array_intersect_key($lineTax, $lines),
			taxMode: $mode,
			lineDiscount: $lineDiscount,
			shippingWaived: $waived,
			discountLine: $discount instanceof AppliedDiscount ? [
				'code'           => $discount->code,
				'type'           => $discount->type,
				'value'          => $discount->value,
				'amount'         => $discounted,
				'shippingWaived' => $waived,
				'recurring'      => $discount->recurring,
			] : null,
			shippingKnown: $known,
		);
	}

	/**
	 * Each line's share of the discount: never more than the line, and
	 * nothing for a line the order does not have.
	 *
	 * @param array<string,OrderLineDraft> $lines
	 *
	 * @return array<string,int>
	 */
	private function lineDiscounts(array $lines, ?AppliedDiscount $discount): array
	{
		$shares = [];
		foreach ($lines as $id => $line) {
			$shares[$id] = max(0, min($line->lineTotal, (int)($discount?->lineAmounts[$id] ?? 0)));
		}

		return $shares;
	}

	/**
	 * The rate the order ships at, or null when nothing in it ships.
	 *
	 * @param array<string,OrderLineDraft> $lines
	 */
	private function rate(array $lines, Money $subtotal, ?Address $address, ?string $rateId, bool $estimate = false): ?ShippingRate
	{
		$ships = array_filter($lines, static fn (OrderLineDraft $line): bool => $line->ships());
		if ($ships === []) {
			return null;
		}

		$rates = $this->shipping->rates($lines, $subtotal, $address);
		if ($rates === []) {
			// An estimate carries on without shipping, and says it has none.
			return $estimate ? null : throw CommerceException::noShipping();
		}
		if ($rateId === null || $rateId === '') {
			return $rates[0];
		}

		foreach ($rates as $rate) {
			if ($rate->id === $rateId) {
				return $rate;
			}
		}

		throw CommerceException::shippingRateInvalid();
	}
}
