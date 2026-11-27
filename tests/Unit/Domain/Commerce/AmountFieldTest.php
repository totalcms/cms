<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Cart\AmountField;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\MoneyFilter;
use TotalCMS\Domain\Commerce\Service\MoneyFormatter;
use TotalCMS\Support\Config;

/*
 * What the add form asks for when the buyer names the price: where the
 * field starts, its limits and the suggested amounts that fit them.
 */

beforeEach(function (): void {
	$this->field = function (array $product, array $settings = []): array {
		$config           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->commerce = $settings;
		$config->locale   = 'en_US';
		$commerce         = new CommerceSettings($config);

		return (new AmountField($commerce, new MoneyFilter(new MoneyFormatter(), $commerce, $config)))
			->variables(ProductView::fromObject($product + ['id' => 'give', 'title' => 'Give', 'customPrice' => true]));
	};
});

test('a fixed-price product asks for nothing', function (): void {
	expect(($this->field)(['price' => 10, 'customPrice' => false]))->toBe(['customPrice' => false]);
});

test('the field starts at the price, with plain numbers', function (): void {
	$vars = ($this->field)(['price' => 19.5, 'minPrice' => 5]);

	expect($vars['amount'])->toBe('19.5')
		->and($vars['minPrice'])->toBe('5')
		->and($vars['maxPrice'])->toBe('10000')
		->and($vars['currency'])->toBe('USD');
});

test('a product with no price starts empty', function (): void {
	expect(($this->field)(['price' => 0])['amount'])->toBe('');
});

test('suggested amounts outside the minimum and the store ceiling are left out', function (): void {
	$vars = ($this->field)(['price' => 10, 'minPrice' => 5, 'suggestedPrices' => [2, 5, 25.5, 500]], ['customPriceMax' => 100]);

	expect(array_column($vars['suggestedPrices'], 'amount'))->toBe(['5', '25.5'])
		->and(array_column($vars['suggestedPrices'], 'formatted'))->toBe(['$5.00', '$25.50']);
});

test('a suggested amount under the 50-cent floor a payment can carry is not offered', function (): void {
	$vars = ($this->field)(['price' => 10, 'suggestedPrices' => [0.25, 0.5, 3]]);

	expect(array_column($vars['suggestedPrices'], 'amount'))->toBe(['0.5', '3']);
});
