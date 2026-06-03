<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use Cron\CronExpression;

/**
 * Decides whether a cron schedule is due, given the last time it fired.
 */
final class ScheduleTicker
{
	/**
	 * Due when the most recent scheduled slot (<= now) is strictly after the
	 * last fire. A never-fired schedule is due once its previous slot has
	 * passed.
	 */
	public function isDue(string $cron, ?string $lastFireIso, \DateTimeImmutable $now): bool
	{
		if ($cron === '' || !CronExpression::isValidExpression($cron)) {
			return false;
		}

		$expression  = new CronExpression($cron);
		$previousRun = \DateTimeImmutable::createFromInterface($expression->getPreviousRunDate($now, 0, true));

		if ($lastFireIso === null) {
			return $previousRun <= $now;
		}

		return $previousRun > new \DateTimeImmutable($lastFireIso);
	}
}
