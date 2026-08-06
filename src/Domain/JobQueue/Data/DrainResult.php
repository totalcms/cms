<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JobQueue\Data;

/**
 * Outcome of one drain pass.
 *
 * `deadlineHit` distinguishes "queue is empty" from "ran out of time with work
 * left" — the caller reports them differently, and a cron monitor should treat
 * neither as a failure.
 */
final readonly class DrainResult
{
	/**
	 * @param array<string,int> $byType
	 * @param array<string,int> $byCollection
	 */
	public function __construct(
		public int $processed,
		public int $succeeded,
		public int $failed,
		public bool $deadlineHit,
		public array $byType = [],
		public array $byCollection = [],
	) {
	}
}
