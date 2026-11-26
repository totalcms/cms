<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

use TotalCMS\Domain\Commerce\Order\OrderLineDraft;
use TotalCMS\Domain\Commerce\Order\Totals;
use TotalCMS\Domain\Commerce\Payment\Recurrence;

/**
 * The part of an order that is billed again every period: its recurring
 * lines, and what they come to each time.
 */
final readonly class SubscriptionDraft
{
	/**
	 * @param array<string,OrderLineDraft> $lines        the recurring lines, keyed by their order line id
	 * @param Totals                       $totals       one period: the recurring lines with the discount that repeats, and their tax
	 * @param string                       $discountCode the code used at checkout, or ''
	 */
	public function __construct(
		public Recurrence $recurrence,
		public array $lines,
		public Totals $totals,
		public string $discountCode = '',
	) {
	}
}
