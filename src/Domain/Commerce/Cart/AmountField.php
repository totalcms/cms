<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\MoneyConverter;
use TotalCMS\Domain\Commerce\Service\MoneyFilter;

/**
 * What the add-to-cart form needs to ask for an amount, for a product whose
 * buyer names the price: where the field starts, its limits, and the
 * suggested amounts that fit them. Numbers are written plainly (no grouping,
 * no symbol, no trailing zeros) so they sit in an attribute as they are.
 *
 * The attributes are a courtesy to the buyer. The cart checks the amount again.
 */
final readonly class AmountField
{
	public function __construct(
		private CommerceSettings $settings,
		private MoneyFilter $money,
	) {
	}

	/**
	 * The variables the form template takes for the amount field.
	 *
	 * @return array<string,mixed>
	 */
	public function variables(ProductView $product): array
	{
		if (!$product->customPrice) {
			return ['customPrice' => false];
		}

		$currency  = $this->settings->currency();
		$ceiling   = $this->settings->customPriceMax();
		$converter = new MoneyConverter();
		$suggested = [];
		foreach ($product->suggestedPrices as $price) {
			if ($price <= 0 || $price < $product->minPrice || $price > $ceiling) {
				continue;
			}
			$suggested[] = [
				'amount'    => $this->plain($price),
				'formatted' => ($this->money)($converter->fromPrice($price, $currency)->amount(), $currency),
			];
		}

		return [
			'customPrice'     => true,
			'amount'          => $product->price > 0 ? $this->plain($product->price) : '',
			'minPrice'        => $this->plain($product->minPrice),
			'maxPrice'        => $this->plain($ceiling),
			'currency'        => $currency,
			'suggestedPrices' => $suggested,
		];
	}

	/** An amount as plain digits in the store currency: 10, 19.99, never 10.00. */
	private function plain(float $amount): string
	{
		$text = (new MoneyConverter())->fromPrice($amount, $this->settings->currency())->toDecimal();

		return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
	}
}
