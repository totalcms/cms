<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JobQueue\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobProcessorLock;
use TotalCMS\Domain\JobQueue\Service\JobQueueHealth;
use TotalCMS\Support\Config;

final class JobQueueHealthTest extends TestCase
{
	/** @param array<string,mixed> $dashboard */
	private function makeConfig(array $dashboard = []): Config
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$prop   = new \ReflectionProperty(Config::class, 'dashboard');
		$prop->setValue($config, $dashboard);

		return $config;
	}

	private function jobAgedMinutes(int $minutes): JobData
	{
		return JobData::fromArray([
			'id'        => '1',
			'type'      => JobData::TYPE_VIEW_UPDATE,
			'status'    => JobData::STATUS_PENDING,
			'createdAt' => gmdate('Y-m-d H:i:s', time() - ($minutes * 60)),
		]);
	}

	/**
	 * @param array<JobData> $pending
	 * @param array<JobData> $inProgress
	 */
	private function makeService(array $pending, array $inProgress, bool $running, array $dashboard = []): JobQueueHealth
	{
		$repo = $this->createMock(JobRepository::class);
		$repo->method('fetchPendingJobs')->willReturn($pending);
		$repo->method('fetchInProgressJobs')->willReturn($inProgress);

		$lock = $this->createMock(JobProcessorLock::class);
		$lock->method('isRunning')->willReturn($running);

		return new JobQueueHealth($repo, $lock, $this->makeConfig($dashboard));
	}

	public function testStalledWhenOldestExceedsThresholdAndProcessorIdle(): void
	{
		$status = $this->makeService([$this->jobAgedMinutes(60)], [], running: false)->status();

		$this->assertTrue($status->stalled);
		$this->assertSame(1, $status->pendingCount);
		$this->assertGreaterThanOrEqual(59, $status->oldestAgeMinutes);
		$this->assertSame(30, $status->thresholdMinutes);
	}

	public function testNotStalledWhenUnderThreshold(): void
	{
		$status = $this->makeService([$this->jobAgedMinutes(5)], [], running: false)->status();

		$this->assertFalse($status->stalled);
		$this->assertSame(1, $status->pendingCount);
	}

	public function testNotStalledWhenProcessorRunning(): void
	{
		// Old jobs, but a drain is actively in progress (lock held) — don't warn.
		$status = $this->makeService([$this->jobAgedMinutes(60)], [], running: true)->status();

		$this->assertFalse($status->stalled);
	}

	public function testNotStalledWhenQueueEmpty(): void
	{
		$status = $this->makeService([], [], running: false)->status();

		$this->assertFalse($status->stalled);
		$this->assertSame(0, $status->pendingCount);
		$this->assertSame(0, $status->oldestAgeMinutes);
	}

	public function testInProgressJobsCountTowardStall(): void
	{
		// A processor that died mid-job leaves stuck in-progress jobs; with the
		// lock free and the job old, that's still a stall.
		$status = $this->makeService([], [$this->jobAgedMinutes(60)], running: false)->status();

		$this->assertTrue($status->stalled);
		$this->assertSame(1, $status->pendingCount);
	}

	public function testCustomThresholdFromDashboardConfig(): void
	{
		$status = $this->makeService(
			[$this->jobAgedMinutes(60)],
			[],
			running: false,
			dashboard: ['jobQueueStalledMinutes' => 120],
		)->status();

		$this->assertFalse($status->stalled, '60min < 120min threshold');
		$this->assertSame(120, $status->thresholdMinutes);
	}
}
