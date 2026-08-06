<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JobQueue\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JobQueue\Service\JobQueueDrainer;
use TotalCMS\Domain\JobQueue\Service\JobRunner;

/**
 * The deadline exists to stop a job being interrupted mid-flight. An interrupted
 * job is reset to pending with its attempt count preserved (JobRepository's
 * resetJobStatus keeps it deliberately), so three timeouts would permanently
 * fail a job that is merely slow. Stopping between jobs avoids that entirely.
 */
final class JobQueueDrainerTest extends TestCase
{
	/** @param list<bool> $outcomes one entry per job the queue will yield */
	private function drainerFor(array $outcomes, ?int &$pulled = null): JobQueueDrainer
	{
		$remaining = $outcomes;
		$pulled    = 0;

		$runner = $this->createMock(JobRunner::class);
		$runner->method('hasPendingJobs')->willReturnCallback(
			static fn (): bool => $remaining !== []
		);
		$runner->method('processNextJobWithDetails')->willReturnCallback(
			function () use (&$remaining, &$pulled): ?array {
				if ($remaining === []) {
					return null;
				}
				$success = array_shift($remaining);
				$pulled++;

				return [
					'success' => $success,
					'job'     => ['id' => $pulled, 'type' => 'import', 'collection' => 'blog'],
					'error'   => $success ? null : 'boom',
				];
			}
		);

		return new JobQueueDrainer($runner);
	}

	public function testWithoutADeadlineItDrainsEverything(): void
	{
		$result = $this->drainerFor([true, true, true])->drain();

		$this->assertSame(3, $result->processed);
		$this->assertSame(3, $result->succeeded);
		$this->assertSame(0, $result->failed);
		$this->assertFalse($result->deadlineHit);
	}

	public function testItCountsFailuresSeparately(): void
	{
		$result = $this->drainerFor([true, false, true])->drain();

		$this->assertSame(3, $result->processed);
		$this->assertSame(2, $result->succeeded);
		$this->assertSame(1, $result->failed);
	}

	public function testAnAlreadyExpiredBudgetStartsNoJobAtAll(): void
	{
		// The whole point: work not started is work left pending, not work
		// half-done. A job must never be picked up only to be killed.
		$drainer = $this->drainerFor([true, true, true], $pulled);

		$result = $drainer->drain(0);

		$this->assertSame(0, $result->processed);
		$this->assertSame(0, $pulled, 'no job may be pulled once the budget is gone');
		$this->assertTrue($result->deadlineHit);
	}

	public function testAnEmptyQueueIsNotADeadlineHit(): void
	{
		$result = $this->drainerFor([])->drain(30);

		$this->assertSame(0, $result->processed);
		$this->assertFalse($result->deadlineHit, 'nothing to do is not the same as out of time');
	}

	public function testAGenerousBudgetStillDrainsEverything(): void
	{
		$result = $this->drainerFor([true, true, true])->drain(30);

		$this->assertSame(3, $result->processed);
		$this->assertFalse($result->deadlineHit);
	}

	public function testItTalliesByTypeAndCollectionForReporting(): void
	{
		$result = $this->drainerFor([true, true])->drain();

		$this->assertSame(['import' => 2], $result->byType);
		$this->assertSame(['blog' => 2], $result->byCollection);
	}
}
