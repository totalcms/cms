<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\JobQueue\Service\JobQueueDrainer;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Process the job queue.
 *
 * Migrated from resources/bin/processJobs.php.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class JobsProcessCommand extends BaseCommand
{
	/**
	 * How long a failed job is kept before being pruned automatically.
	 *
	 * A failed job is diagnostic output rather than data — long enough to be
	 * noticed and investigated, short enough that the queue does not become a
	 * permanent error log.
	 */
	private const FAILED_JOB_RETENTION_DAYS = 15;

	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('jobs:process')
			->setDescription('Process pending job queue')
			->addOption('memory', 'm', InputOption::VALUE_REQUIRED, 'Memory limit', '512M')
			->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Stop starting new jobs after this many seconds. Leftovers stay queued for the next run.');
	}

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		// Memory limit
		$memory = $input->getOption('memory');
		if (is_string($memory)) {
			ini_set('memory_limit', $memory);
		}

		// Lock file
		$lockFilePath = PathUtils::absolutePath($this->totalcms->config->systemDir(), '.processJobs.lock');
		$lockFile     = @fopen($lockFilePath, 'c');
		if ($lockFile === false) {
			return $this->outputError($input, $output, 'Unable to open lock file.');
		}

		if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
			fclose($lockFile);

			return $this->outputError($input, $output, 'Job processor is already running.');
		}

		ftruncate($lockFile, 0);
		fwrite($lockFile, (string)getmypid());

		// Release lock on shutdown
		register_shutdown_function(function () use ($lockFile, $lockFilePath): void {
			flock($lockFile, LOCK_UN);
			fclose($lockFile);
			@unlink($lockFilePath);
		});

		$verbose = $output->isVerbose();
		$isJson  = $this->isJson($input);

		$startTime = microtime(true);
		$jobRunner = $this->totalcms->jobRunner();

		// Reset stuck jobs
		$stuckCount = $jobRunner->resetStuckJobs();

		// Maintenance runs before the queue is inspected, and so before the
		// empty-queue early returns below — a queue holding nothing but old
		// failures is exactly the case that needs pruning, and it used to exit
		// before ever reaching it. Reporting the status afterwards also means
		// the counts describe what is actually left.
		$maintenance = $jobRunner->maintenance(self::FAILED_JOB_RETENTION_DAYS);

		// Initial queue status
		$initialStatus = $jobRunner->getQueueStatus();
		$initialByType = $jobRunner->getQueueByType();

		if (!$isJson) {
			$this->printSeparator($output);
			$output->writeln('Total CMS Job Processor');
			$this->printSeparator($output);

			if ($verbose) {
				$output->writeln('Memory limit: ' . ini_get('memory_limit'));
			}

			if ($stuckCount > 0) {
				$output->writeln('');
				$output->writeln("Recovered {$stuckCount} stuck job(s) from previous crash.");
			}

			if ($maintenance['pruned'] > 0) {
				$output->writeln('');
				$output->writeln(sprintf(
					'Pruned %d failed job(s) older than %d days.',
					$maintenance['pruned'],
					self::FAILED_JOB_RETENTION_DAYS
				));
			}

			if ($verbose) {
				$output->writeln('Jobqueue vacuumed to reclaim disk space.');
			}

			$output->writeln('');
			$output->writeln('Initial Queue Status:');
			$this->printTableRow($output, $initialStatus);

			if ($verbose && array_sum($initialByType) > 0) {
				$output->writeln('');
				$output->writeln('Jobs by Type:');
				$this->printTableRow($output, $initialByType);
			}

			if ($initialStatus['Total'] === 0) {
				$output->writeln('');
				$output->writeln('No jobs in queue.');
				$this->printSeparator($output);

				return Command::SUCCESS;
			}
		}

		// Check for empty queue in JSON mode
		if ($isJson && $initialStatus['Total'] === 0) {
			$output->writeln((string)json_encode([
				'stuck_recovered'  => $stuckCount,
				'processed'        => 0,
				'succeeded'        => 0,
				'failed'           => 0,
				'maintenance'      => $maintenance,
				'duration_seconds' => 0,
			], JSON_PRETTY_PRINT));

			return Command::SUCCESS;
		}

		// Retry failed jobs
		if (!$isJson) {
			$output->writeln('');
			$this->printSeparator($output);
			$output->writeln('Retrying failed jobs...');
		}

		$retryStats = $jobRunner->retryFailedJobsWithStats();

		if (!$isJson) {
			if ($retryStats['total_failed'] > 0) {
				$output->writeln("  Failed jobs found: {$retryStats['total_failed']}");
				$output->writeln("  Retried:           {$retryStats['retried']}");
				$output->writeln("  Skipped (max attempts): {$retryStats['skipped']}");
			} else {
				$output->writeln('  No failed jobs to retry.');
			}

			$output->writeln('');
			$this->printSeparator($output);
			$output->writeln('Processing pending jobs...');
		}

		// Enable import optimization
		$optimizedCollections = $jobRunner->enableImportOptimization();
		if (count($optimizedCollections) > 0 && $verbose && !$isJson) {
			$output->writeln('  Optimizing ' . count($optimizedCollections) . ' collection(s) for bulk import');
		}

		// Process jobs. The loop lives in JobQueueDrainer so the HTTP cron
		// endpoint runs exactly the same code — including the deadline handling,
		// which is the part that must not drift between the two.
		$maxSeconds = $input->getOption('max-seconds');
		$deadline   = is_numeric($maxSeconds) ? max(0, (int)$maxSeconds) : null;

		$drain            = $this->totalcms->container()->get(JobQueueDrainer::class)->drain($deadline);
		$processed        = $drain->processed;
		$succeeded        = $drain->succeeded;
		$failed           = $drain->failed;
		$jobsByCollection = $drain->byCollection;
		$jobsByType       = $drain->byType;

		// Finalize import optimization
		if (count($optimizedCollections) > 0) {
			if ($verbose && !$isJson) {
				$output->writeln('');
				$output->writeln('  Finalizing ' . count($optimizedCollections) . ' collection(s) (rebuilding indexes)...');
			}
			$jobRunner->finalizeImportOptimization($optimizedCollections);
		}

		$endTime       = microtime(true);
		$executionTime = round($endTime - $startTime, 2);
		$peakMemory    = memory_get_peak_usage(true);

		if ($isJson) {
			ksort($jobsByType);
			ksort($jobsByCollection);
			$data = [
				'stuck_recovered'  => $stuckCount,
				'processed'        => $processed,
				'succeeded'        => $succeeded,
				'failed'           => $failed,
				'deadline_hit'     => $drain->deadlineHit,
				'by_type'          => $jobsByType,
				'by_collection'    => $jobsByCollection,
				'maintenance'      => $maintenance,
				'duration_seconds' => $executionTime,
			];
			$output->writeln((string)json_encode($data, JSON_PRETTY_PRINT));

			return Command::SUCCESS;
		}

		// Human output summary
		$output->writeln('');
		$this->printSeparator($output);
		$output->writeln('Processing Summary');
		$this->printSeparator($output);

		$output->writeln('');
		$output->writeln('Results:');
		$this->printTableRow($output, [
			'Total Processed' => $processed,
			'Succeeded'       => $succeeded,
			'Failed'          => $failed,
		]);

		if ($drain->deadlineHit) {
			$output->writeln('');
			$output->writeln(sprintf(
				'Stopped after %d seconds with jobs still queued. They will be picked up on the next run.',
				(int)$deadline
			));
		}

		if ($verbose && count($jobsByType) > 0) {
			ksort($jobsByType);
			$output->writeln('');
			$output->writeln('Processed by Type:');
			$this->printTableRow($output, $jobsByType);
		}

		if ($verbose && count($jobsByCollection) > 0) {
			ksort($jobsByCollection);
			$output->writeln('');
			$output->writeln('Processed by Collection:');
			$this->printTableRow($output, $jobsByCollection, 25);
		}

		$finalStatus = $jobRunner->getQueueStatus();
		$output->writeln('');
		$output->writeln('Final Queue Status:');
		$this->printTableRow($output, $finalStatus);

		$output->writeln('');
		$this->printSeparator($output);
		$output->writeln(sprintf('Completed in %.2f seconds', $executionTime));
		if ($verbose) {
			$output->writeln(sprintf('Peak memory: %.1f MB', $peakMemory / 1024 / 1024));
		}
		$this->printSeparator($output);
		$output->writeln('');

		return Command::SUCCESS;
	}

	/**
	 * @param array<string,int|string> $data
	 */
	private function printTableRow(OutputInterface $output, array $data, int $labelWidth = 15): void
	{
		foreach ($data as $label => $value) {
			$output->writeln(sprintf("  %-{$labelWidth}s %8s", $label . ':', $value));
		}
	}

	private function printSeparator(OutputInterface $output, int $width = 50): void
	{
		$output->writeln(str_repeat('-', $width));
	}
}
