<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Automation;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Automation\Service\ScheduleTicker;

final class ScheduleTickerTest extends TestCase
{
	private function at(string $iso): \DateTimeImmutable
	{
		return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
	}

	public function testDueWhenTheScheduledSlotHasPassedSinceLastFire(): void
	{
		$ticker = new ScheduleTicker();

		// '0 1 * * *' = 01:00 daily; fired yesterday → due now (past today's slot).
		expect($ticker->isDue('0 1 * * *', '2026-05-30T01:00:00+00:00', $this->at('2026-05-31T01:05:00+00:00')))
			->toBeTrue();
	}

	public function testNotDueWhenAlreadyFiredForThisSlot(): void
	{
		$ticker = new ScheduleTicker();

		expect($ticker->isDue('0 1 * * *', '2026-05-31T01:00:00+00:00', $this->at('2026-05-31T01:05:00+00:00')))
			->toBeFalse();
	}

	public function testNeverFiredScheduleIsDueOncePreviousSlotPassed(): void
	{
		$ticker = new ScheduleTicker();

		expect($ticker->isDue('0 1 * * *', null, $this->at('2026-05-31T02:00:00+00:00')))->toBeTrue();
	}

	public function testInvalidCronIsNeverDue(): void
	{
		$ticker = new ScheduleTicker();

		expect($ticker->isDue('not a cron', null, $this->at('2026-05-31T02:00:00+00:00')))->toBeFalse();
		expect($ticker->isDue('', null, $this->at('2026-05-31T02:00:00+00:00')))->toBeFalse();
	}
}
