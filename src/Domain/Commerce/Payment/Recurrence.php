<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

/**
 * How often a subscription line is billed, and how long it is free first.
 */
final readonly class Recurrence
{
	/** The longest span a provider bills in one period, per interval (Stripe's limits: three years). */
	private const LONGEST = ['day' => 1095, 'week' => 156, 'month' => 36, 'year' => 3];

	public const MAX_TRIAL_DAYS = 730;

	/**
	 * @param string $interval day | week | month | year
	 *
	 * @throws \InvalidArgumentException for a period a provider cannot bill
	 */
	public function __construct(
		public string $interval,
		public int $intervalCount = 1,
		public int $trialDays = 0,
	) {
		$longest = self::LONGEST[$interval] ?? throw new \InvalidArgumentException("A billing interval is day, week, month or year, not '{$interval}'.");

		if ($intervalCount < 1 || $intervalCount > $longest) {
			throw new \InvalidArgumentException("A subscription can be billed every 1 to {$longest} {$interval}s, not every {$intervalCount}.");
		}
		if ($trialDays < 0 || $trialDays > self::MAX_TRIAL_DAYS) {
			throw new \InvalidArgumentException('A free trial is 0 to ' . self::MAX_TRIAL_DAYS . " days, not {$trialDays}.");
		}
	}
}
