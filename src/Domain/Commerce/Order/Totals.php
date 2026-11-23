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
	) {
	}
}
