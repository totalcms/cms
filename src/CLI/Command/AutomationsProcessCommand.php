<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Automation\Service\AutomationTicker;
use TotalCMS\Support\ProcessLock;

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
		$lock = ProcessLock::open($this->totalcms->config->systemDir() . '/.processAutomations.lock');
		if ($lock === null || !$lock->acquire()) {
			$output->writeln('Automations processor already running.');

			return Command::SUCCESS;
		}
		$lock->releaseOnShutdown();

		$result = $this->totalcms->container()->get(AutomationTicker::class)->tick();

		return $this->outputData($input, $output, $result);
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$output->writeln(sprintf('Fired %d automation(s).', (int)($data['count'] ?? 0)));
	}
}
