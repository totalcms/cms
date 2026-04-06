<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;

class CacheClearCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('cache:clear')
			->setDescription('Clear all caches');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$results = $this->totalcms->clearCache();

		return $this->outputData($input, $output, $results);
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$output->writeln('');
		$output->writeln('<info>Cache cleared successfully.</info>');
		$output->writeln('');

		$rows = [];
		foreach ($data as $backend => $result) {
			$status = 'N/A';
			if (is_array($result)) {
				$status = !empty($result['cleared']) ? 'Cleared' : 'Skipped';
			}
			$rows[] = [(string) $backend, $status];
		}

		TableHelper::renderList($output, ['Backend', 'Status'], $rows);
		$output->writeln('');
	}
}
