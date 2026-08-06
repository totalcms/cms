<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JobQueue\Service;

use TotalCMS\Domain\JobQueue\Data\DrainResult;

/**
 * Processes pending jobs until the queue is empty or a time budget runs out.
 *
 * Extracted from JobsProcessCommand so the CLI and the HTTP cron endpoint share
 * one loop; two copies would drift, and the deadline handling is the part that
 * must not.
 *
 * The budget is checked BEFORE pulling each job, never during one. A job that is
 * interrupted mid-flight gets reset to pending with its attempt count intact
 * (see JobRepository::resetJobStatus), so three interruptions would permanently
 * fail a job whose only sin is being slower than the request window. Stopping
 * between jobs means the work either happens or waits.
 */
final readonly class JobQueueDrainer
{
	public function __construct(
		private JobRunner $jobRunner,
	) {
	}

	/**
	 * @param int|null $deadlineSeconds null runs to completion (CLI default)
	 */
	public function drain(?int $deadlineSeconds = null): DrainResult
	{
		$start = microtime(true);

		$processed    = 0;
		$succeeded    = 0;
		$failed       = 0;
		$deadlineHit  = false;
		$byType       = [];
		$byCollection = [];

		while ($this->jobRunner->hasPendingJobs()) {
			if ($deadlineSeconds !== null && (microtime(true) - $start) >= $deadlineSeconds) {
				$deadlineHit = true;
				break;
			}

			$result = $this->jobRunner->processNextJobWithDetails();
			if ($result === null) {
				break;
			}

			$processed++;

			// `job` and `success` are guaranteed by processNextJobWithDetails()'s
			// declared shape; only the keys inside `job` are open.
			$job                       = $result['job'];
			$type                      = (string)($job['type'] ?? 'unknown');
			$collection                = (string)($job['collection'] ?? 'unknown');
			$byType[$type]             = ($byType[$type] ?? 0) + 1;
			$byCollection[$collection] = ($byCollection[$collection] ?? 0) + 1;

			if ($result['success']) {
				$succeeded++;
			} else {
				$failed++;
			}
		}

		return new DrainResult(
			processed: $processed,
			succeeded: $succeeded,
			failed: $failed,
			deadlineHit: $deadlineHit,
			byType: $byType,
			byCollection: $byCollection,
		);
	}
}
