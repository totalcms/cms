<?php

declare(strict_types=1);

namespace TotalCMS\Action\Cron;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Automation\Service\AutomationQueue;
use TotalCMS\Domain\Automation\Service\AutomationRunner;
use TotalCMS\Domain\Automation\Service\AutomationStateStore;
use TotalCMS\Domain\Automation\Service\ScheduleTicker;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Fires due scheduled automations over HTTP, for hosts whose cron can only fetch
 * a URL. The counterpart to `tcms automations:process`, running the same
 * sequence against the same lock file.
 *
 * No time budget here, unlike the job queue. A tick fires whatever schedules are
 * due and returns, and the queue drain is bounded by what is already queued —
 * there is no open-ended backlog to work through. A handler slow enough to time
 * out is a guard-rail problem (AutomationGuard), not a budgeting one.
 */
final readonly class CronAutomationsAction
{
	private const LOCK_FILE = '.system/.processAutomations.lock';

	public function __construct(
		private JsonRenderer $renderer,
		private AutomationLoader $loader,
		private AutomationRunner $runner,
		private AutomationStateStore $state,
		private ScheduleTicker $ticker,
		private AutomationQueue $queue,
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
			// Drain queued async runs (webhook async + event triggers) first, so a
			// backlog never delays the schedules that are due right now.
			$drained = 0;
			$this->queue->drain(function (array $job) use (&$drained): void {
				$this->runner->run(
					(string)($job['id'] ?? ''),
					is_array($job['trigger'] ?? null) ? $job['trigger'] : [],
					is_array($job['args'] ?? null) ? $job['args'] : [],
					null,
					is_array($job['event'] ?? null) ? $job['event'] : null,
				);
				$drained++;
			});

			$fired = $this->fireDueSchedules();

			return $this->renderer->json($response, [
				'fired'   => $fired,
				'count'   => count($fired),
				'drained' => $drained,
			], 200);
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/** @return list<string> ids of the automations fired */
	private function fireDueSchedules(): array
	{
		$now   = new \DateTimeImmutable('now', $this->siteTimezone());
		$fired = [];

		foreach ($this->loader->all() as $automation) {
			$id = $automation->id;

			foreach ($automation->triggers as $triggerKey => $trigger) {
				if (($trigger['type'] ?? '') !== 'schedule') {
					continue;
				}

				$triggerId = (string)($trigger['id'] ?? $triggerKey);

				if (!$this->ticker->isDue((string)($trigger['cron'] ?? ''), $this->state->lastFire($id, $triggerId), $now)) {
					continue;
				}

				$this->runner->run($id, $trigger, []);
				$this->state->recordFire($id, $triggerId, $now->format('c'));
				$fired[] = $id;
			}
		}

		return $fired;
	}

	/**
	 * Cron expressions are evaluated in the site timezone (Settings → General),
	 * falling back to UTC if it's unset or invalid — matching the CLI exactly.
	 */
	private function siteTimezone(): \DateTimeZone
	{
		try {
			$tz = $this->config->timezone;

			return new \DateTimeZone($tz !== '' ? $tz : 'UTC');
		} catch (\Exception) {
			return new \DateTimeZone('UTC');
		}
	}
}
