<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Maintenance;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Repair\Data\RepairFilters;
use TotalCMS\Domain\Repair\Data\RepairReport;
use TotalCMS\Domain\Repair\Service\CollectionFileRepairService;

/**
 * Rebuild file/image/gallery/depot property metadata that was blanked in an
 * object's JSON (e.g. a PUT form that omitted the field) from the files still on
 * disk. Dry-run by default; --apply writes. Recovery only — authored fields
 * (alt/tags/featured/order/depot password) cannot be restored.
 */
class RepairFilesCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('repair:files')
			->setDescription('Rebuild blanked file/image/gallery/depot metadata from files still on disk')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addOption('apply', null, InputOption::VALUE_NONE, 'Write the repairs (default: dry-run report only)')
			->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to field type(s): file|image|gallery|depot')
			->addOption('property', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to property name(s)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collection = (string)$input->getArgument('collection');
		if (!$this->totalcms->collectionFetcher()->collectionExists($collection)) {
			return $this->outputError($input, $output, "Collection '{$collection}' not found.");
		}

		$filters = new RepairFilters(
			array_values(array_map(static fn (mixed $v): string => (string)$v, (array)$input->getOption('type'))),
			array_values(array_map(static fn (mixed $v): string => (string)$v, (array)$input->getOption('property'))),
		);

		$service = $this->totalcms->container()->get(CollectionFileRepairService::class);
		$report  = (bool)$input->getOption('apply')
			? $service->apply($collection, $filters)
			: $service->scan($collection, $filters);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		} else {
			$this->renderReport($output, $report);
		}

		return $report->failedCount() > 0 ? Command::FAILURE : Command::SUCCESS;
	}

	private function renderReport(OutputInterface $output, RepairReport $report): void
	{
		$output->writeln(sprintf("<info>repair:files</info> — '%s', scanned %d object(s)", $report->collection, $report->scanned));
		$output->writeln('');

		foreach ($report->candidates as $candidate) {
			$status = match (true) {
				$candidate->error !== null    => "<error>error: {$candidate->error}</error>",
				$candidate->applied === true  => '<info>rebuilt</info>',
				$candidate->applied === false => '<error>apply failed</error>',
				default                       => 'would rebuild',
			};
			$output->writeln(sprintf('  %-24s %-18s %-8s %d file(s)  → %s', $candidate->objectId, $candidate->path(), $candidate->type, $candidate->fileCount, $status));
		}

		if ($report->candidates === []) {
			$output->writeln('  (nothing to repair)');
		}
		$output->writeln('');

		if ($report->applied) {
			$output->writeln(sprintf('Summary: %d scanned · %d rebuilt · %d failed · %d blank with no files', $report->scanned, $report->appliedOkCount(), $report->failedCount(), $report->blankWithoutFiles));
		} else {
			$output->writeln(sprintf('Summary: %d scanned · %d would be repaired · %d blank with no files', $report->scanned, $report->repairableCount(), $report->blankWithoutFiles));
			if ($report->repairableCount() > 0) {
				$output->writeln('Run again with <comment>--apply</comment> to write the repairs.');
			}
		}

		$output->writeln('<comment>Note: alt text, tags, featured flags, gallery order and depot passwords cannot be recovered.</comment>');
	}
}
