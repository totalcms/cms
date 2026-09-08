<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Collection\Service\CollectionFormatConverter;

/**
 * Change a collection's storage format by rewriting every object file.
 * Safe to interrupt: the new file is written before the old one is removed
 * and reads resolve by existence, so re-running finishes the job.
 */
class CollectionConvertCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('collection:convert')
			->setDescription('Convert a collection between json and markdown object storage')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target format: json or markdown')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collection = (string)$input->getArgument('collection');
		$to         = (string)$input->getOption('to');
		$dryRun     = (bool)$input->getOption('dry-run');

		if ($to === '') {
			return $this->outputError($input, $output, 'Provide the target format with --to=json or --to=markdown.');
		}

		try {
			$report = $this->totalcms->container()->get(CollectionFormatConverter::class)->convert($collection, $to, $dryRun);
		} catch (\DomainException|\UnexpectedValueException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'collection' => $report->collection,
				'from'       => $report->from,
				'to'         => $report->to,
				'converted'  => $report->converted,
				'skipped'    => $report->skipped,
				'failed'     => $report->failed,
				'dryRun'     => $report->dryRun,
			], JSON_PRETTY_PRINT));

			return $report->failed === [] ? Command::SUCCESS : Command::FAILURE;
		}

		if ($report->converted === 0 && $report->failed === []) {
			$output->writeln("<comment>'{$report->collection}' is already stored as {$report->to}; nothing to do.</comment>");
		} else {
			$verb    = $report->dryRun ? 'Would convert' : 'Converted';
			$message = "{$verb} {$report->converted} object(s) in '{$report->collection}' from {$report->from} to {$report->to}";
			if ($report->skipped > 0) {
				$message .= ", {$report->skipped} already in {$report->to}, skipped";
			}
			$message .= $report->dryRun ? ' (dry run).' : '.';
			$output->writeln("<info>{$message}</info>");
		}
		foreach ($report->failed as $id => $reason) {
			$verb = $reason === 'write' ? 'write' : 'read';
			$output->writeln("<error>Could not {$verb} '{$id}'; left as is.</error>");
		}
		if (!$report->dryRun && $report->converted > 0) {
			$output->writeln('Index rebuilt.');
		}

		return $report->failed === [] ? Command::SUCCESS : Command::FAILURE;
	}
}
