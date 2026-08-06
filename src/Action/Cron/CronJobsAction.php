<?php

declare(strict_types=1);

namespace TotalCMS\Action\Cron;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\JobQueue\Service\JobQueueDrainer;
use TotalCMS\Domain\JobQueue\Service\JobRunner;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Drains the job queue over HTTP, for hosts whose cron can only fetch a URL.
 *
 * Holds the same flock as `tcms jobs:process` so a slow drain cannot stack when
 * cron fires every minute, and so a CLI run and an HTTP run can never overlap.
 *
 * Returns 200 for every normal outcome — including "another run holds the lock"
 * and "nothing to do" — because cron monitors alert on non-2xx and neither of
 * those is a fault.
 *
 * This is a fallback, not the recommended setup: see the honest limits in the
 * operations docs. A real cron running `tcms jobs:process` has no time budget
 * and drains the whole queue in one pass.
 */
final readonly class CronJobsAction
{
	/** Fraction of max_execution_time to spend, leaving room for the response. */
	private const BUDGET_FRACTION = 0.8;

	/** Used when max_execution_time is 0 (unlimited), so the request cannot hang behind a proxy. */
	private const UNLIMITED_FALLBACK = 60;

	/** Mirrors JobsProcessCommand::FAILED_JOB_RETENTION_DAYS. */
	private const FAILED_JOB_RETENTION_DAYS = 15;

	private const LOCK_FILE = '.system/.processJobs.lock';

	public function __construct(
		private JsonRenderer $renderer,
		private JobQueueDrainer $drainer,
		private JobRunner $jobRunner,
		private Config $config,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$lockPath = PathUtils::absolutePath($this->config->datadir, self::LOCK_FILE);
		$lock     = @fopen($lockPath, 'c');

		if ($lock === false) {
			return $this->renderer->json($response, ['skipped' => 'no-lock-file'], 200);
		}

		if (!flock($lock, LOCK_EX | LOCK_NB)) {
			fclose($lock);

			return $this->renderer->json($response, ['skipped' => 'already-running'], 200);
		}

		try {
			$stuck       = $this->jobRunner->resetStuckJobs();
			$maintenance = $this->jobRunner->maintenance(self::FAILED_JOB_RETENTION_DAYS);
			$drain       = $this->drainer->drain($this->budget());

			return $this->renderer->json($response, [
				'processed'       => $drain->processed,
				'succeeded'       => $drain->succeeded,
				'failed'          => $drain->failed,
				'stuck_recovered' => $stuck,
				'pruned'          => $maintenance['pruned'],
				'deadline_hit'    => $drain->deadlineHit,
				'remaining'       => $this->jobRunner->hasPendingJobs(),
			], 200);
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/**
	 * Seconds of work to attempt before stopping cleanly.
	 *
	 * Deliberately short of the real limit: the response still has to be written,
	 * and a job that overruns its own estimate should not push the request past
	 * the point where the server kills it.
	 */
	private function budget(): int
	{
		$limit = (int)ini_get('max_execution_time');

		if ($limit <= 0) {
			return self::UNLIMITED_FALLBACK;
		}

		return max(1, (int)floor($limit * self::BUDGET_FRACTION));
	}
}
