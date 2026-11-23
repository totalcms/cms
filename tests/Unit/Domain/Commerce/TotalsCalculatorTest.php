<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Discount\AppliedDiscount;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\Address;
use TotalCMS\Domain\Commerce\Order\NoShippingCalculator;
use TotalCMS\Domain\Commerce\Order\NoTaxCalculator;
use TotalCMS\Domain\Commerce\Order\OrderLineDraft;
use TotalCMS\Domain\Commerce\Order\ShippingCalculator;
use TotalCMS\Domain\Commerce\Order\ShippingRate;
use TotalCMS\Domain\Commerce\Order\TaxCalculator;
use TotalCMS\Domain\Commerce\Order\TotalsCalculator;
use TotalCMS\Domain\Money\Money;

/*
 * One place adds an order up: subtotal, then the discount, then shipping,
 * then tax. The order that is charged and the cart a buyer was shown both
 * come from here.
 */

function totalsLine(string $id, int $unit, int $qty = 1, bool $ships = true, string $type = 'physical'): OrderLineDraft
{
	return new OrderLineDraft(lineId: $id, productId: 'p-' . $id, variantId: '', sku: '', title: 'Item ' . $id, type: $type, qty: $qty, unitPrice: $unit, listPrice: $unit, priceSource: 'catalog', lineTotal: $unit * $qty, taxable: true, requiresShipping: $ships);
}

/** A shipping calculator that offers these rates and keeps what it was asked. */
function stubShipping(ShippingRate ...$rates): ShippingCalculator
{
	return new class($rates) implements ShippingCalculator {
		public ?Money $askedSubtotal = null;

		/** @param array<ShippingRate> $rates */
		public function __construct(private readonly array $rates)
		{
		}

		public function rates(array $lines, Money $subtotal, ?Address $address): array
		{
			$this->askedSubtotal = $subtotal;

			return array_values($this->rates);
		}
	};
}

/** A tax calculator that taxes every line 10% and keeps the amounts it was shown. */
function stubTax(string $mode = 'exclusive'): TaxCalculator
{
	return new class($mode) implements TaxCalculator {
		/** @var array<string,int> */
		public array $seen = [];

		public function __construct(private readonly string $mode)
		{
		}

		public function mode(): string
		{
			return $this->mode;
		}

		public function calculate(array $lines, int $shipping, ?Address $address, string $currency): array
		{
			$tax = [];
			foreach ($lines as $id => $line) {
				$this->seen[$id] = $line->netTotal();
				$tax[$id]        = intdiv($line->netTotal(), 10);
			}

			return ['lines' => $tax, 'shipping' => 0, 'taxLines' => [['label' => 'Tax', 'rate' => '10', 'amount' => array_sum($tax)]]];
		}
	};
}

it('adds up to the subtotal when there is no tax and no shipping charge', function (): void {
	$totals = (new TotalsCalculator(new NoTaxCalculator(), new NoShippingCalculator()))->calculate(['l1' => totalsLine('l1', 1999, 2), 'l2' => totalsLine('l2', 500)], null, null, 'USD');

	expect($totals->subtotal)->toBe(4498)
		->and($totals->discountTotal)->toBe(0)
		->and($totals->shippingTotal)->toBe(0)
		->and($totals->taxTotal)->toBe(0)
		->and($totals->grandTotal)->toBe(4498)
		->and($totals->taxMode)->toBe('none')
		->and($totals->taxLines)->toBe([])
		->and($totals->lineTax)->toBe(['l1' => 0, 'l2' => 0])
		->and($totals->discountLine)->toBeNull();
});

it('charges the one shipping rate and records it', function (): void {
	$totals = (new TotalsCalculator(new NoTaxCalculator(), stubShipping(new ShippingRate('flat', 'Flat rate', Money::of(500, 'USD')))))->calculate(['l1' => totalsLine('l1', 1999)], null, null, 'USD');

	expect($totals->shippingTotal)->toBe(500)
		->and($totals->shippingLine)->toBe(['rateId' => 'flat', 'label' => 'Flat rate', 'amount' => 500])
		->and($totals->grandTotal)->toBe(2499);
});

it('charges no shipping when nothing ships', function (): void {
	$shipping = stubShipping(new ShippingRate('flat', 'Flat rate', Money::of(500, 'USD')));
	$totals   = (new TotalsCalculator(new NoTaxCalculator(), $shipping))->calculate(['l1' => totalsLine('l1', 1999, ships: false, type: 'digital')], null, null, 'USD');

	expect($totals->shippingTotal)->toBe(0)
		->and($totals->shippingLine)->toBeNull()
		->and($shipping->askedSubtotal)->toBeNull();
});

it('refuses an order that ships when there is no rate for it', function (): void {
	$calculator = new TotalsCalculator(new NoTaxCalculator(), stubShipping());

	expect(fn () => $calculator->calculate(['l1' => totalsLine('l1', 1999)], null, null, 'USD'))
		->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('no_shipping')->and($e->status)->toBe(422));
});

it('takes the rate the buyer chose, and refuses one that is not offered', function (): void {
	$calculator = new TotalsCalculator(new NoTaxCalculator(), stubShipping(new ShippingRate('slow', 'Slow', Money::of(300, 'USD')), new ShippingRate('fast', 'Fast', Money::of(900, 'USD'))));
	$lines      = ['l1' => totalsLine('l1', 1999)];

	expect($calculator->calculate($lines, null, 'fast', 'USD')->shippingTotal)->toBe(900)
		->and($calculator->calculate($lines, null, null, 'USD')->shippingTotal)->toBe(300)
		->and($calculator->calculate($lines, null, '', 'USD')->shippingTotal)->toBe(300)
		->and(fn () => $calculator->calculate($lines, null, 'teleport', 'USD'))
		->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('shipping_rate_invalid'));
});

it('adds exclusive tax to the total and leaves inclusive tax inside it', function (string $mode, int $grand): void {
	$totals = (new TotalsCalculator(stubTax($mode), new NoShippingCalculator()))->calculate(['l1' => totalsLine('l1', 2000)], null, null, 'USD');

	expect($totals->taxTotal)->toBe(200)
		->and($totals->lineTax)->toBe(['l1' => 200])
		->and($totals->taxMode)->toBe($mode)
		->and($totals->taxLines)->toBe([['label' => 'Tax', 'rate' => '10', 'amount' => 200]])
		->and($totals->grandTotal)->toBe($grand);
})->with([['exclusive', 2200], ['inclusive', 2000]]);

describe('with a discount', function (): void {
	beforeEach(function (): void {
		$this->lines    = ['l1' => totalsLine('l1', 1999), 'l2' => totalsLine('l2', 2000)];
		$this->discount = fn (bool $freeShipping = false): AppliedDiscount => new AppliedDiscount('SAVE', 'fixed', '6.00', 600, ['l1' => 300, 'l2' => 300], [], $freeShipping, 'once');
	});

	it('takes the discount off before shipping and tax are worked out', function (): void {
		$tax      = stubTax();
		$shipping = stubShipping(new ShippingRate('flat', 'Flat rate', Money::of(0, 'USD')));
		$totals   = (new TotalsCalculator($tax, $shipping))->calculate($this->lines, null, null, 'USD', ($this->discount)());

		expect($totals->discountTotal)->toBe(600)
			->and($totals->lineDiscount)->toBe(['l1' => 300, 'l2' => 300])
			->and($tax->seen)->toBe(['l1' => 1699, 'l2' => 1700])
			->and($shipping->askedSubtotal?->amount())->toBe(3399)
			->and($totals->discountLine)->toBe(['code' => 'SAVE', 'type' => 'fixed', 'value' => '6.00', 'amount' => 600, 'shippingWaived' => 0, 'recurring' => 'once']);
	});

	it('comes to the subtotal less the discount', function (): void {
		$totals = (new TotalsCalculator(new NoTaxCalculator(), new NoShippingCalculator()))->calculate($this->lines, null, null, 'USD', ($this->discount)());

		expect($totals->subtotal)->toBe(3999)->and($totals->grandTotal)->toBe(3399);
	});

	it('waives shipping for a free-shipping code and records what it waived', function (): void {
		$totals = (new TotalsCalculator(new NoTaxCalculator(), stubShipping(new ShippingRate('flat', 'Flat rate', Money::of(500, 'USD')))))->calculate($this->lines, null, null, 'USD', ($this->discount)(freeShipping: true));

		expect($totals->shippingTotal)->toBe(0)
			->and($totals->shippingWaived)->toBe(500)
			->and($totals->discountTotal)->toBe(600)
			->and($totals->discountLine['shippingWaived'])->toBe(500)
			->and($totals->grandTotal)->toBe(3399);
	});

	it('ignores a discount amount for a line the order does not have', function (): void {
		$stray  = new AppliedDiscount('SAVE', 'fixed', '6.00', 900, ['l1' => 300, 'gone' => 600], [], false, 'once');
		$totals = (new TotalsCalculator(new NoTaxCalculator(), new NoShippingCalculator()))->calculate($this->lines, null, null, 'USD', $stray);

		expect($totals->discountTotal)->toBe(300)->and($totals->lineDiscount)->toBe(['l1' => 300, 'l2' => 0]);
	});
});
