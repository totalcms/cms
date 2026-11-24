<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Inventory;

/**
 * What became of a change to a product's stock.
 */
final readonly class StockResult
{
	/**
	 * @param bool $tracked whether the product counts stock at all; when not, nothing was changed
	 * @param int  $short   how many of what was asked for were not there to take
	 * @param int  $stock   the count afterward
	 */
	public function __construct(
		public bool $tracked,
		public int $short = 0,
		public int $stock = 0,
	) {
	}

	public static function untracked(): self
	{
		return new self(false);
	}
}
