<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Storage\Sequence;

/**
 * Gives each order the next number. Numbers only go up, and one is never
 * given twice; an order that fails to save leaves a gap, which is harmless.
 */
final readonly class OrderNumberer
{
	private const SEQUENCE = 'commerce-orders';

	public function __construct(
		private Sequence $sequence,
		private CommerceSettings $settings,
	) {
	}

	public function next(): int
	{
		return $this->sequence->next(self::SEQUENCE, $this->settings->orderNumberStart());
	}
}
