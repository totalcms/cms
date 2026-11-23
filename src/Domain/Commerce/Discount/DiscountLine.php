<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Discount;

use TotalCMS\Domain\Commerce\Order\OrderLineDraft;

/**
 * What working out a discount needs to know about one line of a cart.
 */
final readonly class DiscountLine
{
	/**
	 * @param int  $lineTotal what the line costs before any discount, in minor units
	 * @param bool $recurring the line is billed again each period
	 * @param bool $inTrial   a recurring line whose first period is free: nothing is due on it now
	 */
	public function __construct(
		public string $lineId,
		public string $productId,
		public string $category,
		public int $lineTotal,
		public bool $recurring,
		public bool $inTrial,
	) {
	}

	public static function fromDraft(string $lineId, OrderLineDraft $line): self
	{
		return new self($lineId, $line->productId, $line->category, $line->lineTotal, $line->isRecurring(), $line->isRecurring() && $line->trialDays > 0);
	}
}
