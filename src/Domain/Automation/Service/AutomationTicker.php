<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Support\Config;

/**
 * One automations tick, shared by `tcms automations:process` and the cron
 * URL: drain the queued async runs (webhook async + event triggers) first so
 * a backlog never delays a schedule that is due right now, then fire every
 * due schedule. The caller holds the single-flight lock.
 */
readonly class AutomationTicker
{
	public function __construct(
		private AutomationLoader $loader,
		private AutomationRunner $runner,
		private AutomationStateStore $state,
		private ScheduleTicker $schedules,
		private AutomationQueue $queue,
		private Config $config,
	) {
	}

	/** @return array{fired: list<string>, count: int, drained: int} */
	public function tick(): array
	{
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

		return ['fired' => $fired, 'count' => count($fired), 'drained' => $drained];
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

				if (!$this->schedules->isDue((string)($trigger['cron'] ?? ''), $this->state->lastFire($id, $triggerId), $now)) {
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
	 * falling back to UTC when it is unset or invalid.
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
