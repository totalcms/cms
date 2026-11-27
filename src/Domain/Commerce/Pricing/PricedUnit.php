<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Pricing;

use TotalCMS\Domain\Money\Money;

/**
 * The price of one unit of a line.
 */
final readonly class PricedUnit
{
	public const CATALOG = 'catalog';

	/** The buyer named the price, so no resolver and no discount touches it. */
	public const CUSTOM = 'custom';

	/**
	 * @param Money  $listPrice the catalog price, before any resolver
	 * @param Money  $unitPrice what is charged
	 * @param string $source    `catalog`, `custom` for a price the buyer named, or the id of the resolver that set the unit price
	 */
	public function __construct(
		public Money $listPrice,
		public Money $unitPrice,
		public string $source,
	) {
	}
}
