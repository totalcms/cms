<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Order\Address;
use TotalCMS\Domain\Commerce\Order\OrderLineDraft;
use TotalCMS\Domain\Commerce\Order\Regions;
use TotalCMS\Domain\Commerce\Order\TableTaxCalculator;
use TotalCMS\Domain\Commerce\Order\TaxCalculator;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Support\Config;

/*
 * The store's own tax table: a rate for a country, or for one region of it,
 * set in Settings → Commerce. The buyer's address picks the rate, and each
 * line is taxed on what is charged for it.
 */

/**
 * A calculator for a store with these tax settings.
 *
 * @param list<array{country?: string, region?: string, rate?: string, label?: string}> $rates
 */
function taxTable(array $rates, string $mode = 'exclusive', bool $taxShipping = false): TableTaxCalculator
{
	$config           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->commerce = ['taxMode' => $mode, 'taxShipping' => $taxShipping, 'taxRates' => $rates];

	return new TableTaxCalculator(new CommerceSettings($config));
}

function taxedLine(string $id, int $lineTotal, bool $taxable = true, int $discount = 0): OrderLineDraft
{
	return new OrderLineDraft(lineId: $id, productId: 'p-' . $id, variantId: '', sku: '', title: 'Item ' . $id, type: 'physical', qty: 1, unitPrice: $lineTotal, listPrice: $lineTotal, priceSource: 'catalog', lineTotal: $lineTotal, taxable: $taxable, requiresShipping: true, discountTotal: $discount);
}

function taxAt(string $country, string $region = ''): Address
{
	return new Address(country: $country, region: $region);
}

const CA_TEN = [['country' => 'US', 'region' => 'CA', 'rate' => '10', 'label' => 'CA sales tax']];

describe('tax added to prices', function (): void {
	it('is the rate on what the line costs, rounded half up', function (string $rate, int $lineTotal, int $tax): void {
		$out = taxTable([['country' => 'US', 'rate' => $rate]])->calculate(['l1' => taxedLine('l1', $lineTotal)], 0, taxAt('US'), 'USD');

		expect($out['lines'])->toBe(['l1' => $tax]);
	})->with([
		'8.875% of 19.99'          => ['8.875', 1999, 177],
		'10% of 28.00'             => ['10', 2800, 280],
		'half a cent goes up'      => ['2.5', 100, 3],
		'under half a cent is nil' => ['0.4', 100, 0],
		'a rate with no decimals'  => ['20', 1200, 240],
		'four decimals are kept'   => ['7.0125', 100000, 7013],
	]);

	it('rounds each line by itself, so the lines add up to the total', function (): void {
		$out = taxTable([['country' => 'US', 'rate' => '8.875']])->calculate(['l1' => taxedLine('l1', 333), 'l2' => taxedLine('l2', 333), 'l3' => taxedLine('l3', 333)], 0, taxAt('US'), 'USD');

		// 29.55 cents each, so 30 each: 90, where the three together would round to 89.
		expect($out['lines'])->toBe(['l1' => 30, 'l2' => 30, 'l3' => 30])
			->and($out['taxLines'])->toBe([['label' => 'Tax', 'rate' => '8.875', 'amount' => 90]]);
	});

	it('taxes what is charged for a line, after its share of a discount', function (): void {
		$out = taxTable(CA_TEN)->calculate(['l1' => taxedLine('l1', 2800, discount: 800)], 0, taxAt('US', 'CA'), 'USD');

		expect($out['lines'])->toBe(['l1' => 200]);
	});

	it('leaves a product that is not taxable alone', function (): void {
		$out = taxTable(CA_TEN)->calculate(['l1' => taxedLine('l1', 1000), 'l2' => taxedLine('l2', 5000, taxable: false)], 0, taxAt('US', 'CA'), 'USD');

		expect($out['lines'])->toBe(['l1' => 100, 'l2' => 0]);
	});

	it('taxes shipping only when the store says to', function (bool $taxShipping, int $shippingTax): void {
		$out = taxTable(CA_TEN, taxShipping: $taxShipping)->calculate(['l1' => taxedLine('l1', 1000)], 650, taxAt('US', 'CA'), 'USD');

		expect($out['shipping'])->toBe($shippingTax)
			->and($out['taxLines'][0]['amount'])->toBe(100 + $shippingTax);
	})->with([[true, 65], [false, 0]]);

	it('names the tax as the store named the rate', function (): void {
		$out = taxTable(CA_TEN)->calculate(['l1' => taxedLine('l1', 1000)], 0, taxAt('US', 'CA'), 'USD');

		expect($out['taxLines'])->toBe([['label' => 'CA sales tax', 'rate' => '10', 'amount' => 100]]);
	});
});

describe('tax already in the prices', function (): void {
	it('is the part of the price that is tax, and the price does not grow', function (string $rate, int $lineTotal, int $tax): void {
		$calculator = taxTable([['country' => 'GB', 'rate' => $rate, 'label' => 'VAT']], 'inclusive');

		expect($calculator->mode())->toBe(TaxCalculator::INCLUSIVE)
			->and($calculator->calculate(['l1' => taxedLine('l1', $lineTotal)], 0, taxAt('GB'), 'GBP')['lines'])->toBe(['l1' => $tax]);
	})->with([
		'20% inside 12.00' => ['20', 1200, 200],
		'20% inside 9.99'  => ['20', 999, 166],
		'5% inside 1.00'   => ['5', 100, 5],
	]);

	it('finds the tax inside shipping too, when shipping is taxed', function (): void {
		$out = taxTable([['country' => 'GB', 'rate' => '20']], 'inclusive', true)->calculate(['l1' => taxedLine('l1', 1200)], 600, taxAt('GB'), 'GBP');

		expect($out['shipping'])->toBe(100);
	});
});

describe('which rate applies', function (): void {
	beforeEach(function (): void {
		$this->table = taxTable([
			['country' => '', 'rate' => '1', 'label' => 'Everywhere'],
			['country' => 'US', 'rate' => '2', 'label' => 'United States'],
			['country' => 'US', 'region' => 'CA', 'rate' => '10', 'label' => 'California'],
			['country' => 'CA', 'region' => 'ON', 'rate' => '13', 'label' => 'Ontario'],
		]);
		$this->label = fn (?Address $address): string => $this->table->calculate(['l1' => taxedLine('l1', 1000)], 0, $address, 'USD')['taxLines'][0]['label'] ?? 'none';
	});

	it('is the most exact one for the address', function (string $country, string $region, string $label): void {
		expect(($this->label)(taxAt($country, $region)))->toBe($label);
	})->with([
		'the region'                 => ['US', 'CA', 'California'],
		'another region'             => ['US', 'NY', 'United States'],
		'no region given'            => ['US', '', 'United States'],
		'a country with no rate'     => ['DE', '', 'Everywhere'],
		'a region of another country' => ['CA', 'ON', 'Ontario'],
		// CA the country is not CA the state.
		'Canada, not California'     => ['CA', 'BC', 'Everywhere'],
	]);

	it('knows a state by its name as well as its code, however it is typed', function (string $typed): void {
		expect(($this->label)(taxAt('US', $typed)))->toBe('California');
	})->with(['CA', 'ca', ' Ca ', 'California', 'CALIFORNIA', 'california', 'C.A.']);

	it('knows a Canadian province the same way', function (string $typed): void {
		expect(($this->label)(taxAt('CA', $typed)))->toBe('Ontario');
	})->with(['ON', 'Ontario', 'ontario']);

	it('takes a region the store wrote in several ways', function (string $typed, string $label): void {
		$table = taxTable([['country' => 'AU', 'region' => 'NSW, New South Wales', 'rate' => '10', 'label' => 'NSW']]);

		expect($table->calculate(['l1' => taxedLine('l1', 1000)], 0, taxAt('AU', $typed), 'AUD')['taxLines'][0]['label'] ?? 'none')->toBe($label);
	})->with([['nsw', 'NSW'], ['New South Wales', 'NSW'], ['Victoria', 'none']]);

	it('charges nothing with no address, or with no rate for it', function (): void {
		$only = taxTable(CA_TEN);

		expect($only->calculate(['l1' => taxedLine('l1', 1000)], 500, null, 'USD'))->toBe(['lines' => ['l1' => 0], 'shipping' => 0, 'taxLines' => []])
			->and($only->calculate(['l1' => taxedLine('l1', 1000)], 500, taxAt('US', 'TX'), 'USD'))->toBe(['lines' => ['l1' => 0], 'shipping' => 0, 'taxLines' => []]);
	});

	it('ignores a rate that is not a percentage', function (string $rate): void {
		$out = taxTable([['country' => 'US', 'rate' => $rate]])->calculate(['l1' => taxedLine('l1', 1000)], 0, taxAt('US'), 'USD');

		expect($out['taxLines'])->toBe([]);
	})->with(['', 'ten', '-5', '10%', '1.2.3', '101']);
});

describe('a store that charges no tax', function (): void {
	it('works nothing out, whatever is in the table', function (): void {
		$calculator = taxTable(CA_TEN, 'none');

		expect($calculator->mode())->toBe(TaxCalculator::NONE)
			->and($calculator->calculate(['l1' => taxedLine('l1', 1000)], 500, taxAt('US', 'CA'), 'USD'))->toBe(['lines' => ['l1' => 0], 'shipping' => 0, 'taxLines' => []])
			->and($calculator->needsRegion('US'))->toBeFalse();
	});
});

describe('when the buyer must say which region', function (): void {
	it('is when the store has a rate for one region of their country', function (string $country, bool $needed): void {
		expect(taxTable(CA_TEN)->needsRegion($country))->toBe($needed);
	})->with([['US', true], ['us', true], ['DE', false], ['', false]]);
});

describe('region names', function (): void {
	it('are the same region when a code and a name mean the same place', function (string $country, string $a, string $b, bool $same): void {
		expect(Regions::same($country, $a, $b))->toBe($same);
	})->with([
		['US', 'CA', 'California', true],
		['US', 'ny', 'New York', true],
		['US', 'District of Columbia', 'DC', true],
		['US', 'CA', 'NY', false],
		['CA', 'QC', 'Québec', true],
		['CA', 'QC', 'Quebec', true],
		['DE', 'Bayern', 'bayern', true],
		['DE', 'Bayern', 'Bavaria', false],
		['US', '', '', false],
	]);
});
