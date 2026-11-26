<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * What an order comes to. Every amount is minor units of the order's currency.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a value object: every
 *                                                  field is one total, named
 */
final readonly class Totals
{
	/**
	 * @param int                                                       $discountTotal  the sum of the line discounts; free shipping is not in it
	 * @param int                                                       $grandTotal     subtotal − discount + shipping, plus tax when tax is exclusive
	 * @param list<array{label: string, rate: string, amount: int}>     $taxLines
	 * @param array{rateId: string, label: string, amount: int}|null    $shippingLine   null when nothing ships
	 * @param array<string,int>                                         $lineTax        line id => its tax
	 * @param array<string,int>                                         $lineDiscount   line id => its share of the discount
	 * @param int                                                       $shippingWaived what a free-shipping code took off
	 * @param array{code: string, type: string, value: string, amount: int, shippingWaived: int, recurring: string}|null $discountLine
	 * @param bool                                                      $shippingKnown  false only in an estimate, for an order that ships to a place no rate covers yet
	 */
	public function __construct(
		public int $subtotal,
		public int $discountTotal,
		public int $shippingTotal,
		public int $taxTotal,
		public int $grandTotal,
		public array $taxLines,
		public ?array $shippingLine,
		public array $lineTax,
		public string $taxMode,
		public array $lineDiscount = [],
		public int $shippingWaived = 0,
		public ?array $discountLine = null,
		public bool $shippingKnown = true,
	) {
	}

	/**
	 * The same totals with the tax left to the payment provider: none of
	 * the store's own, on the order or on any line, and the total as it is
	 * without tax added on top. The provider adds its own when the buyer
	 * pays.
	 */
	public function leftToProvider(): self
	{
		return new self(...[
			'taxTotal'   => 0,
			'taxLines'   => [],
			'lineTax'    => array_fill_keys(array_keys($this->lineTax), 0),
			'grandTotal' => $this->subtotal - $this->discountTotal + $this->shippingTotal,
			'taxMode'    => TaxCalculator::PROVIDER,
		] + get_object_vars($this));
	}
}
