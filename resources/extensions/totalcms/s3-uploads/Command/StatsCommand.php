<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Bundled\S3Uploads\Serving\DownloadCounter;

/**
 * tcms s3:stats [collection] [--json].
 *
 * Download counts of the files stored in the bucket, most downloaded first.
 */
final class StatsCommand extends Command
{
	/** @param \Closure(): DownloadCounter $counter resolved when the command runs */
	public function __construct(private readonly \Closure $counter)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('s3:stats')
			->setDescription('Show how often files in the S3 bucket were downloaded')
			->addArgument('collection', InputArgument::OPTIONAL, 'Only this collection')
			->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collection = $input->getArgument('collection');
		$rows       = [];

		$only       = is_string($collection) && $collection !== '' ? $collection : null;
		$counts     = ($this->counter)()->all($only);

		// One row per property with its all-time total, and under a depot one
		// row per file it holds now. A property whose file is gone keeps its row.
		foreach (($this->counter)()->allTotals($only) as $name => $objects) {
			foreach ($objects as $id => $totals) {
				$current = $counts[$name][$id] ?? [];
				foreach ($totals as $property => $total) {
					$files  = array_filter($current, fn (string $key): bool => str_starts_with($key, $property . '/'), ARRAY_FILTER_USE_KEY);
					$rows[] = [
						'collection' => $name,
						'id'         => $id,
						'file'       => $files === [] ? $property : $property . ' (all files)',
						'downloads'  => $files === [] ? ($current[$property] ?? 0) : array_sum($files),
						'allTime'    => $total,
					];
					foreach ($files as $file => $downloads) {
						$rows[] = ['collection' => $name, 'id' => $id, 'file' => $file, 'downloads' => $downloads, 'allTime' => null];
					}
				}
			}
		}
		usort($rows, fn (array $a, array $b): int => [$b['downloads'], $a['collection'], $a['id'], $a['file']] <=> [$a['downloads'], $b['collection'], $b['id'], $b['file']]);

		if ((bool)$input->getOption('json')) {
			$output->writeln((string)json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}
		if ($rows === []) {
			$output->writeln('No downloads counted yet.');

			return Command::SUCCESS;
		}

		(new Table($output))
			->setHeaders(['Object', 'File', 'Downloads', 'All time'])
			->setRows(array_map(fn (array $row): array => [$row['collection'] . '/' . $row['id'], $row['file'], (string)$row['downloads'], (string)($row['allTime'] ?? '')], $rows))
			->render();

		return Command::SUCCESS;
	}
}
