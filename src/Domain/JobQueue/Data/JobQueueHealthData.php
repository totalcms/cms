<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JobQueue\Data;

/**
 * Snapshot of job-queue health for the admin warning. `stalled` is the single
 * flag the UI keys off; the rest is context for the message.
 */
final readonly class JobQueueHealthData
{
	public function __construct(
		public bool $stalled,
		public int $pendingCount,
		public int $oldestAgeMinutes,
		public int $thresholdMinutes,
	) {
	}
}
