<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Discount\DiscountCalculator;
use TotalCMS\Domain\Commerce\Discount\DiscountData;
use TotalCMS\Domain\Commerce\Discount\DiscountLine;
use TotalCMS\Domain\Commerce\Order\OrderLineDraft;
use TotalCMS\Domain\Commerce\Service\MoneyConverter;

/*
 * What a code takes off, and off which lines. Nothing here reads or writes
 * anything: given a code and a cart's lines it answers with the amount,
 * already split so the shares add up to it exactly.
 */

/** @param array<string,mixed> $fields */
function discountCode(array $fields = []): DiscountData
{
	return DiscountData::fromObject($fields + ['id' => 'save', 'type' => 'percent', 'value' => 15]);
}

function discountLine(string $id, int $total, string $product = '', string $category = '', bool $recurring = false, bool $inTrial = false): DiscountLine
{
	return new DiscountLine($id, $product !== '' ? $product : 'p-' . $id, $category, $total, $recurring, $inTrial);
}

beforeEach(function (): void {
	$this->calc  = new DiscountCalculator(new MoneyConverter());
	$this->lines = [discountLine('l1', 1999, 'tee', 'Clothing'), discountLine('l2', 2000, 'mug', 'Kitchen')];
});

it('splits by largest remainder so the shares add up', function (int $amount, array $weights, array $out): void {
	expect($this->calc->split($amount, $weights))->toBe($out);
})->with([
	[600, ['l1' => 1999, 'l2' => 2000], ['l1' => 300, 'l2' => 300]],
	[100, ['l1' => 333, 'l2' => 333, 'l3' => 333], ['l1' => 34, 'l2' => 33, 'l3' => 33]],
	[3999, ['l1' => 1999, 'l2' => 2000], ['l1' => 1999, 'l2' => 2000]],
	[0, ['l1' => 500], ['l1' => 0]],
	[1, ['l1' => 1, 'l2' => 999], ['l1' => 0, 'l2' => 1]],
	[500, ['l1' => 0, 'l2' => 0], ['l1' => 0, 'l2' => 0]],
	[10, [], []],
]);

it('never gives a line more than the line', function (): void {
	$shares = $this->calc->split(2998, ['l1' => 1999, 'l2' => 1, 'l3' => 999]);

	expect(array_sum($shares))->toBe(2998)
		->and($shares['l1'])->toBeLessThanOrEqual(1999)
		->and($shares['l2'])->toBeLessThanOrEqual(1)
		->and($shares['l3'])->toBeLessThanOrEqual(999);
});

it('takes a percent of the eligible lines', function (): void {
	$applied = $this->calc->apply(discountCode(), $this->lines, 'USD');

	expect($applied->code)->toBe('SAVE')
		->and($applied->type)->toBe('percent')
		->and($applied->value)->toBe('15')
		->and($applied->amount)->toBe(600)
		->and($applied->lineAmounts)->toBe(['l1' => 300, 'l2' => 300])
		->and($applied->recurringLineAmounts)->toBe([])
		->and($applied->freeShipping)->toBeFalse()
		->and($applied->recurring)->toBe('once');
});

it('rounds a percent half up', function (): void {
	// 12.5% of 0.99 + 0.01 = 12.5 cents → 13.
	expect($this->calc->apply(discountCode(['value' => 12.5]), [discountLine('l1', 99), discountLine('l2', 1)], 'USD')->amount)->toBe(13);
});

it('never takes more than everything, whatever the percent says', function (): void {
	expect($this->calc->apply(discountCode(['value' => 250]), $this->lines, 'USD')->amount)->toBe(3999);
});

it('caps a fixed amount at the eligible total', function (): void {
	$applied = $this->calc->apply(discountCode(['type' => 'fixed', 'value' => 50]), $this->lines, 'USD');

	expect($applied->amount)->toBe(3999)->and($applied->lineAmounts)->toBe(['l1' => 1999, 'l2' => 2000]);
});

it('takes a fixed amount that is smaller than the cart', function (): void {
	$applied = $this->calc->apply(discountCode(['type' => 'fixed', 'value' => '5.50']), $this->lines, 'USD');

	expect($applied->amount)->toBe(550)->and(array_sum($applied->lineAmounts))->toBe(550);
});

it('limits a code to its products and categories', function (array $fields, array $lineAmounts): void {
	expect($this->calc->apply(discountCode($fields), $this->lines, 'USD')->lineAmounts)->toBe($lineAmounts);
})->with([
	'one product'              => [['products' => ['tee']], ['l1' => 300]],
	'one category'             => [['categories' => ['Kitchen']], ['l2' => 300]],
	'a category typed lower'   => [['categories' => [' kitchen ']], ['l2' => 300]],
	'a product or a category'  => [['products' => ['tee'], 'categories' => ['Kitchen']], ['l1' => 300, 'l2' => 300]],
	'nothing in the cart fits' => [['products' => ['hat']], []],
]);

it('gives free shipping no line amount', function (): void {
	$applied = $this->calc->apply(discountCode(['type' => 'free_shipping', 'value' => 0]), $this->lines, 'USD');

	expect($applied->amount)->toBe(0)->and($applied->lineAmounts)->toBe([])->and($applied->freeShipping)->toBeTrue();
});

it('fills recurringLineAmounts only for a code that repeats', function (string $recurring, array $expected): void {
	$lines   = [discountLine('l1', 1000, recurring: true), discountLine('l2', 2000)];
	$applied = $this->calc->apply(discountCode(['value' => 10, 'recurring' => $recurring]), $lines, 'USD');

	expect($applied->lineAmounts)->toBe(['l1' => 100, 'l2' => 200])
		->and($applied->recurringLineAmounts)->toBe($expected)
		->and($applied->recurring)->toBe($recurring);
})->with([['forever', ['l1' => 100]], ['once', []]]);

it('keeps a first-payment code off a line in trial', function (): void {
	$lines = [discountLine('l1', 1000, recurring: true, inTrial: true), discountLine('l2', 2000)];
	$once  = $this->calc->apply(discountCode(['value' => 10, 'recurring' => 'once']), $lines, 'USD');
	$ever  = $this->calc->apply(discountCode(['value' => 10, 'recurring' => 'forever']), $lines, 'USD');

	// Nothing is due on a line in trial now, so no code takes anything off it now.
	expect($once->lineAmounts)->toBe(['l2' => 200])
		->and($once->recurringLineAmounts)->toBe([])
		->and($ever->lineAmounts)->toBe(['l2' => 200])
		->and($ever->recurringLineAmounts)->toBe(['l1' => 100]);
});

it('says which lines a code can apply to', function (): void {
	$trial = [discountLine('l1', 1000, recurring: true, inTrial: true)];

	expect($this->calc->eligible(discountCode(['products' => ['hat']]), $this->lines))->toBe([])
		->and($this->calc->eligible(discountCode(['recurring' => 'once']), $trial))->toBe([])
		->and($this->calc->eligible(discountCode(['recurring' => 'forever']), $trial))->toHaveCount(1);
});

it('reads a line from an order being built', function (): void {
	$draft = new OrderLineDraft(lineId: 'l1', productId: 'club', variantId: '', sku: '', title: 'Club', type: 'digital', qty: 1, unitPrice: 1000, listPrice: 1000, priceSource: 'catalog', lineTotal: 1000, taxable: true, requiresShipping: false, category: 'Members', billing: 'recurring', interval: 'month', intervalCount: 1, trialDays: 14);
	$line  = DiscountLine::fromDraft('l1', $draft);

	expect([$line->lineId, $line->productId, $line->category, $line->lineTotal, $line->recurring, $line->inTrial])->toBe(['l1', 'club', 'Members', 1000, true, true]);
});
