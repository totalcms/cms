<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JobQueue\Service;

use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Data\JobQueueHealthData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Support\Config;

/**
 * Detects a stalled job queue — work is queued but `tcms jobs:process` isn't
 * draining it. Surfaced as a warning on the dashboard + Job Queue Manager.
 *
 * The queue is "stalled" when the oldest waiting job has been waiting longer
 * than the threshold AND the processor isn't currently running. The lock check
 * suppresses the warning during a legitimate long-running drain (e.g. a big
 * import the processor is actively working through).
 */
readonly class JobQueueHealth
{
	private const DEFAULT_THRESHOLD_MINUTES = 30;

	public function __construct(
		private JobRepository $jobRepository,
		private JobProcessorLock $processorLock,
		private Config $config,
	) {
	}

	public function status(): JobQueueHealthData
	{
		$threshold = $this->thresholdMinutes();

		// Pending = queued-but-unstarted; in-progress = picked up but not
		// finished (stuck if the processor died mid-job). Either being old with
		// no processor running means the queue isn't moving.
		$jobs = array_merge(
			$this->jobRepository->fetchPendingJobs(),
			$this->jobRepository->fetchInProgressJobs(),
		);

		$count     = count($jobs);
		$oldestAge = $this->oldestAgeMinutes($jobs);

		$stalled = $count > 0
			&& $oldestAge > $threshold
			&& !$this->processorLock->isRunning();

		return new JobQueueHealthData(
			stalled: $stalled,
			pendingCount: $count,
			oldestAgeMinutes: $oldestAge,
			thresholdMinutes: $threshold,
		);
	}

	private function thresholdMinutes(): int
	{
		$configured = (int)($this->config->dashboard['jobQueueStalledMinutes'] ?? 0);

		return $configured > 0 ? $configured : self::DEFAULT_THRESHOLD_MINUTES;
	}

	/** @param array<JobData> $jobs */
	private function oldestAgeMinutes(array $jobs): int
	{
		$oldest = null;

		foreach ($jobs as $job) {
			// createdAt is a SQLite CURRENT_TIMESTAMP string — always UTC.
			$timestamp = strtotime($job->createdAt . ' UTC');
			if ($timestamp === false) {
				continue;
			}

			if ($oldest === null || $timestamp < $oldest) {
				$oldest = $timestamp;
			}
		}

		if ($oldest === null) {
			return 0;
		}

		return (int)max(0, floor((time() - $oldest) / 60));
	}
}
