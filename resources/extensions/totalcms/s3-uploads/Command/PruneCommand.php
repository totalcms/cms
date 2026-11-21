<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Bundled\S3Uploads\Lifecycle\PruneReport;
use TotalCMS\Bundled\S3Uploads\Lifecycle\PruneService;
use TotalCMS\Bundled\S3Uploads\S3\S3Exception;
use TotalCMS\Bundled\S3Uploads\Upload\UploadRules;

/**
 * tcms s3:prune [--delete] [--older-than=24h] [--max=1000] [--whole-bucket] [--json]
 *
 * Reports the files in the bucket that no object points at. Nothing is
 * deleted without --delete.
 */
final class PruneCommand extends Command
{
	/** @param \Closure(): PruneService $service resolved when the command runs: services do not exist yet when it is registered */
	public function __construct(private readonly \Closure $service)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('s3:prune')
			->setDescription('Find (and with --delete, remove) files in the S3 bucket that no object uses')
			->addOption('delete', null, InputOption::VALUE_NONE, 'Delete the files. Without this the command only reports')
			->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Leave unused files younger than this alone (30m, 24h, 7d)', '24h')
			->addOption('max', null, InputOption::VALUE_REQUIRED, 'Delete at most this many files in one run', '1000')
			->addOption('whole-bucket', null, InputOption::VALUE_NONE, 'Run with no prefix set: treats every file in the bucket as this site\'s')
			->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$olderThan = self::seconds((string)$input->getOption('older-than'));
		if ($olderThan === null) {
			$output->writeln('<error>--older-than takes a number with m, h or d, such as 30m, 24h or 7d.</error>');

			return Command::FAILURE;
		}

		try {
			$report = ($this->service)()->run(
				(bool)$input->getOption('delete'),
				$olderThan,
				max(1, (int)$input->getOption('max')),
				(bool)$input->getOption('whole-bucket'),
			);
		} catch (S3Exception $e) {
			$output->writeln("<error>The bucket refused the request ({$e->s3Code}). {$e->getMessage()}</error>");

			return Command::FAILURE;
		}

		if ((bool)$input->getOption('json')) {
			$output->writeln((string)json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return $report->refused ? Command::FAILURE : Command::SUCCESS;
		}

		return $this->render($output, $report, (bool)$input->getOption('delete'));
	}

	/** "30m", "24h", "7d" or plain seconds, as seconds; null when it is none of those. */
	public static function seconds(string $text): ?int
	{
		if (preg_match('/^(\d+)([smhd]?)\z/', trim($text), $match) !== 1) {
			return null;
		}

		return (int)$match[1] * ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$match[2]];
	}

	private function render(OutputInterface $output, PruneReport $report, bool $asked): int
	{
		if ($report->refused) {
			$output->writeln('<error>No prefix is set, so the whole bucket would count as this site\'s files.</error>');
			$output->writeln('Set a prefix in Extensions → S3 Uploads, or pass --whole-bucket if this bucket holds nothing else.');

			return Command::FAILURE;
		}

		$files = fn (int $count): string => $count . ' file' . ($count === 1 ? '' : 's');
		$size  = UploadRules::formatSize($report->bytes);

		$output->writeln("Listed {$files($report->listed)} in the bucket; objects point at {$report->referenced}.");

		foreach ($report->problems as $problem) {
			$output->writeln("<comment>{$problem}</comment>");
		}
		if ($report->downgraded) {
			$output->writeln('<comment>Nothing was deleted: a file an unreadable object points at would look unused.</comment>');
		}

		if ($report->orphans === 0) {
			$output->writeln('<info>Nothing to clean up.</info>');

			return Command::SUCCESS;
		}

		if ($report->deleted > 0) {
			$output->writeln("<info>Deleted {$files($report->deleted)} ({$size}).</info>");
		} else {
			$output->writeln("Dry run: {$files($report->orphans)} ({$size}) no object uses.");
		}
		foreach ($report->sample as $key) {
			$output->writeln('  ' . $key);
		}
		if ($report->orphans > count($report->sample)) {
			$output->writeln('  … and ' . ($report->orphans - count($report->sample)) . ' more');
		}

		if ($report->capped) {
			$output->writeln('<comment>Stopped at --max. Run again to delete the rest.</comment>');
		}
		if (!$asked) {
			$output->writeln('Run again with --delete to remove them.');
		}

		return Command::SUCCESS;
	}
}
