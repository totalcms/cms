<?php

namespace TotalCMS\Domain\JobQueue\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\Object\Service\ObjectExporter;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Factory\LoggerFactory;

readonly class JobRunner
{
	private LoggerInterface $logger;

	/**
	 * Maximum number of retry attempts for failed jobs.
	 */
	private const MAX_RETRY_ATTEMPTS = 3;

	public function __construct(
		private JobRepository $jobRepository,
		private ObjectImporter $objectImporter,
		private ObjectExporter $objectExporter,
		private IndexBuilder $indexBuilder,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
		->addFileHandler('jobs.log')
		->createLogger('jobrunner');
	}

	/**
	 * Retry all failed jobs by resetting their status to pending.
	 * Jobs that exceed MAX_RETRY_ATTEMPTS will be skipped.
	 */
	public function retryFailedJobs(): void
	{
		$failedJobs   = $this->jobRepository->fetchFailedJobs();
		$retriedCount = 0;
		$skippedCount = 0;

		foreach ($failedJobs as $job) {
			// Check if job has exceeded maximum retry attempts
			if ($job->attempts >= self::MAX_RETRY_ATTEMPTS) {
				$this->logger->warning('Job exceeded max retry attempts, skipping', [
					'job_id'       => $job->id,
					'attempts'     => $job->attempts,
					'max_attempts' => self::MAX_RETRY_ATTEMPTS,
					'type'         => $job->type,
					'collection'   => $job->collection,
				]);
				$skippedCount++;
				continue;
			}

			$this->jobRepository->resetJobStatus($job);
			$this->logger->info('Job retried', array_merge($job->toArray(), [
				'attempt'      => $job->attempts + 1,
				'max_attempts' => self::MAX_RETRY_ATTEMPTS,
			]));
			$retriedCount++;
		}

		$this->logger->info('Retry summary', [
			'total_failed' => count($failedJobs),
			'retried'      => $retriedCount,
			'skipped'      => $skippedCount,
			'max_attempts' => self::MAX_RETRY_ATTEMPTS,
		]);
	}

	public function processPendingJobs(): void
	{
		while ($this->jobRepository->hasPendingJobs()) {
			$this->processNextJob();
		}
		$this->logger->info('Processed all pending jobs');
	}

	/** @SuppressWarnings("PHPMD.ElseExpression") */
	public function processNextJob(): void
	{
		$job = $this->jobRepository->fetchNextJob();
		try {
			$this->processJob($job);
			$this->jobRepository->delete($job);
			$this->logger->info('Job processed successfully', $job->toArray());
		} catch (\Throwable $e) {
			$this->jobRepository->markFailed($job, $e->getMessage());

			// Add additional context if job has reached max attempts
			$logContext = array_merge($job->toArray(), [
				'error'        => $e->getMessage(),
				'backtrace'    => "\n" . $e->getTraceAsString() . "\n",
				'attempt'      => $job->attempts,
				'max_attempts' => self::MAX_RETRY_ATTEMPTS,
			]);

			if ($job->attempts >= self::MAX_RETRY_ATTEMPTS) {
				$this->logger->error('Job failed and exceeded max retry attempts', $logContext);
			} else {
				$this->logger->error('Job failed, can be retried', $logContext);
			}
		}
	}

	private function processJob(JobData $job): void
	{
		switch ($job->type) {
			case JobData::TYPE_IMPORT:
				$this->processImportJob($job);
				break;
			case JobData::TYPE_EXPORT:
				$this->processExportJob($job);
				break;
			case JobData::TYPE_REBUILD:
				$this->processRebuildJob($job);
				break;
			case JobData::TYPE_UPDATE:
				$this->processUpdateJob($job);
				break;
			default:
				$error = 'Unknown job type: ' . $job->type;
				$this->logger->error($error, $job->toArray());
		}
	}

	private function processImportJob(JobData $job): void
	{
		$data = json_decode($job->payload, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$error = 'Invalid JSON payload: ' . json_last_error_msg();
			$this->logger->error($error, $job->toArray());

			return;
		}
		$this->objectImporter->importObject($job->collection, $data);
	}

	private function processRebuildJob(JobData $job): void
	{
		$this->indexBuilder->buildIndex($job->collection);
	}

	private function processUpdateJob(JobData $job): void
	{
		$data = json_decode($job->payload, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$error = 'Invalid JSON payload: ' . json_last_error_msg();
			$this->logger->error($error, $job->toArray());

			return;
		}
		$this->objectImporter->updateObject($job->collection, $data);
	}

	/**
	 * Process export job by exporting collection data.
	 *
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 */
	private function processExportJob(JobData $job): void
	{
		$data = json_decode($job->payload, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$error = 'Invalid JSON payload: ' . json_last_error_msg();
			$this->logger->error($error, $job->toArray());

			return;
		}

		// Export all objects from the collection
		$exportedData = $this->objectExporter->exportAllObjects($job->collection);

		// If a specific export path is provided in the payload, save to file
		if (isset($data['export_path']) && is_string($data['export_path'])) {
			$exportPath = $data['export_path'];
			$jsonData   = json_encode($exportedData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

			if (file_put_contents($exportPath, $jsonData) === false) {
				throw new \RuntimeException("Failed to write export data to: {$exportPath}");
			}

			$this->logger->info("Exported {$job->collection} to {$exportPath}", [
				'collection'   => $job->collection,
				'export_path'  => $exportPath,
				'object_count' => count($exportedData),
			]);
		} else {
			// Log export completion (data could be retrieved elsewhere)
			$this->logger->info("Exported {$job->collection} data", [
				'collection'   => $job->collection,
				'object_count' => count($exportedData),
			]);
		}
	}
}
