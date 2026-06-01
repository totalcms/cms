<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Automation\Service\AutomationRunner;
use TotalCMS\Domain\Automation\Service\AutomationStateStore;
use TotalCMS\Domain\Automation\Service\ScheduleTicker;
use TotalCMS\Domain\Property\Data\DeckData;

/**
 * Fires due scheduled automations. Runs on its own cron line, parallel to
 * jobs:process, so an import backlog never delays a scheduled automation.
 * Single-flight locked so overlapping cron ticks don't double-fire.
 */
class AutomationsProcessCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('automations:process')
			->setDescription('Fire due scheduled automations (run on its own cron line, parallel to jobs:process)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$lockPath = rtrim($this->totalcms->config->datadir, '/') . '/.system/.processAutomations.lock';
		$lock     = @fopen($lockPath, 'c');

		if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
			if ($lock !== false) {
				fclose($lock);
			}
			$output->writeln('Automations processor already running.');

			return Command::SUCCESS;
		}

		register_shutdown_function(static function () use ($lock, $lockPath): void {
			flock($lock, LOCK_UN);
			fclose($lock);
			@unlink($lockPath);
		});

		$loader = $this->totalcms->container()->get(AutomationLoader::class);
		$runner = $this->totalcms->container()->get(AutomationRunner::class);
		$state  = $this->totalcms->container()->get(AutomationStateStore::class);
		$ticker = $this->totalcms->container()->get(ScheduleTicker::class);

		// Drain queued async runs (webhook async + event triggers) first.
		$queue   = $this->totalcms->container()->get(\TotalCMS\Domain\Automation\Service\AutomationQueue::class);
		$drained = 0;
		$queue->drain(function (array $job) use ($runner, &$drained): void {
			$runner->run(
				(string)($job['id'] ?? ''),
				is_array($job['trigger'] ?? null) ? $job['trigger'] : [],
				is_array($job['args'] ?? null) ? $job['args'] : [],
				null,
				is_array($job['event'] ?? null) ? $job['event'] : null,
			);
			$drained++;
		});

		$now   = new \DateTimeImmutable('now', $this->siteTimezone());
		$fired = [];

		foreach ($loader->enabled() as $automation) {
			$id = $automation->id;

			foreach ($this->triggers($automation->properties->get('triggers')) as $triggerKey => $trigger) {
				if (($trigger['type'] ?? '') !== 'schedule') {
					continue;
				}

				$triggerId = (string)($trigger['id'] ?? $triggerKey);

				if (!$ticker->isDue((string)($trigger['cron'] ?? ''), $state->lastFire($id, $triggerId), $now)) {
					continue;
				}

				$runner->run($id, $trigger, []);
				$state->recordFire($id, $triggerId, $now->format('c'));
				$fired[] = $id;
			}
		}

		return $this->outputData($input, $output, ['fired' => $fired, 'count' => count($fired), 'drained' => $drained]);
	}

	/**
	 * Cron expressions are evaluated in the site timezone (Settings → General),
	 * falling back to UTC if it's unset or invalid.
	 */
	private function siteTimezone(): \DateTimeZone
	{
		try {
			$tz = (string)($this->totalcms->config->timezone ?? '');

			return new \DateTimeZone($tz !== '' ? $tz : 'UTC');
		} catch (\Exception) {
			return new \DateTimeZone('UTC');
		}
	}

	/**
	 * @param mixed $triggers the triggers deck property
	 * @return array<string,array<string,mixed>>
	 */
	private function triggers(mixed $triggers): array
	{
		$rows = $triggers instanceof DeckData ? $triggers->transform() : $triggers;
		$out  = [];

		foreach (is_array($rows) ? $rows : [] as $key => $row) {
			if (is_array($row)) {
				$out[(string)$key] = $row;
			}
		}

		return $out;
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$output->writeln(sprintf('Fired %d automation(s).', (int)($data['count'] ?? 0)));
	}
}
