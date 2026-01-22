<?php

namespace TotalCMS\Domain\JobQueue\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\Object\Service\ObjectExporter;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Factory\LoggerFactory;

readonly class JobRunner
{
	public LoggerInterface $logger;

	/**
	 * Maximum number of retry attempts for failed jobs.
	 */
	private const MAX_RETRY_ATTEMPTS = 3;

	public function __construct(
		private JobRepository $jobRepository,
		private ObjectImporter $objectImporter,
		private ObjectExporter $objectExporter,
		private IndexBuilder $indexBuilder,
		private FactoryImporter $factoryImporter,
		private CollectionRepository $collectionRepository,
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
		// Get collections with pending import/update/factory jobs and enable queueRebuildOnSave
		$collectionsToOptimize = $this->enableQueueRebuildForImportCollections();

		// Process all jobs
		while ($this->jobRepository->hasPendingJobs()) {
			$this->processNextJob();
		}

		// Rebuild indexes and restore settings for optimized collections
		$this->finalizeOptimizedCollections($collectionsToOptimize);

		$this->logger->info('Processed all pending jobs');
	}

	/**
	 * Find collections with pending import/update/factory jobs and enable queueRebuildOnSave.
	 *
	 * @return array<CollectionData> Map of collection ID to original queueRebuildOnSave setting
	 */
	private function enableQueueRebuildForImportCollections(): array
	{
		$pendingJobs = $this->jobRepository->fetchPendingJobs();

		// Find unique collections with import-type jobs
		$importCollections = [];
		foreach ($pendingJobs as $job) {
			if (in_array($job->type, [JobData::TYPE_IMPORT, JobData::TYPE_UPDATE, JobData::TYPE_FACTORY], true)) {
				$importCollections[] = $job->collection;
			}
		}

		// Enable queueRebuildOnSave for each collection, storing original settings
		$collectionTempEnabledRebuild = [];
		foreach ($importCollections as $collectionId) {
			$collection = $this->collectionRepository->fetchCollection($collectionId);
			if (!$collection instanceof CollectionData || $collection->queueRebuildOnSave) {
				continue;
			}

			// Enable if not already enabled
			$collection->queueRebuildOnSave = true;
			$this->collectionRepository->saveCollection($collection);
			$this->logger->info('Enabled queueRebuildOnSave for import optimization', [
				'collection' => $collectionId,
			]);

			// Store original setting
			$collectionTempEnabledRebuild[] = $collection;
		}

		return $collectionTempEnabledRebuild;
	}

	/**
	 * Rebuild indexes and restore original queueRebuildOnSave settings.
	 *
	 * @param array<CollectionData> $collectionsToDisableRebuild Map of collection ID to original setting
	 */
	private function finalizeOptimizedCollections(array $collectionsToDisableRebuild): void
	{
		foreach ($collectionsToDisableRebuild as $collection) {
			// Rebuild the index for this collection
			$this->indexBuilder->buildIndex($collection->id);
			$this->logger->info('Rebuilt index after import', ['collection' => $collection->id]);

			$collection->queueRebuildOnSave = false;
			$this->collectionRepository->saveCollection($collection);
			$this->logger->info('Restored queueRebuildOnSave setting', [
				'collection' => $collection->id,
			]);
		}
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
			case JobData::TYPE_FACTORY:
				$this->processFactoryJob($job);
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
		$this->logger->info('Starting rebuild job', [
			'collection' => $job->collection,
			'job_id'     => $job->id,
		]);

		$index = $this->indexBuilder->buildIndex($job->collection);

		$this->logger->info('Rebuild job completed', [
			'collection'    => $job->collection,
			'job_id'        => $job->id,
			'indexed_count' => $index->objects->count(),
		]);
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

	private function processFactoryJob(JobData $job): void
	{
		$data = json_decode($job->payload, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$error = 'Invalid JSON payload: ' . json_last_error_msg();
			$this->logger->error($error, $job->toArray());

			return;
		}

		$quantity = intval($data['quantity'] ?? 1);
		$rules    = $data['rules'] ?? [];

		$importCount = $this->factoryImporter->import($job->collection, $quantity, $rules);

		$this->logger->info('Factory job completed', [
			'collection' => $job->collection,
			'quantity'   => $quantity,
			'imported'   => $importCount,
			'rules'      => $rules,
		]);
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

	/**
	 * Get queue statistics by status.
	 *
	 * @return array<string,int>
	 */
	public function getQueueStatus(): array
	{
		return $this->jobRepository->queueByStatus();
	}

	/**
	 * Reset stuck in-progress jobs (from crashed processes) back to pending.
	 *
	 * @return int Number of jobs reset
	 */
	public function resetStuckJobs(): int
	{
		$count = $this->jobRepository->resetInProgressJobs();
		if ($count > 0) {
			$this->logger->info('Reset stuck in-progress jobs', ['count' => $count]);
		}

		return $count;
	}

	/**
	 * Get queue statistics by job type.
	 *
	 * @return array<string,int>
	 */
	public function getQueueByType(): array
	{
		return $this->jobRepository->queueByType();
	}

	/**
	 * Check if there are pending jobs.
	 */
	public function hasPendingJobs(): bool
	{
		return $this->jobRepository->hasPendingJobs();
	}

	/**
	 * Enable queue rebuild optimization for import-type jobs.
	 * Call this before processing jobs, and call finalizeImportOptimization() after.
	 *
	 * @return array<CollectionData> Collections that were optimized
	 */
	public function enableImportOptimization(): array
	{
		return $this->enableQueueRebuildForImportCollections();
	}

	/**
	 * Finalize import optimization by rebuilding indexes and restoring settings.
	 *
	 * @param array<CollectionData> $collections Collections to finalize
	 */
	public function finalizeImportOptimization(array $collections): void
	{
		$this->finalizeOptimizedCollections($collections);
	}

	/**
	 * Process and return the next job with details.
	 *
	 * @return array{success: bool, job: array<string,mixed>, error?: string}|null
	 */
	public function processNextJobWithDetails(): ?array
	{
		if (!$this->jobRepository->hasPendingJobs()) {
			return null;
		}

		$job = $this->jobRepository->fetchNextJob();
		try {
			$this->processJob($job);
			$this->jobRepository->delete($job);
			$this->logger->info('Job processed successfully', $job->toArray());

			return [
				'success' => true,
				'job'     => $job->toArray(),
			];
		} catch (\Throwable $e) {
			$this->jobRepository->markFailed($job, $e->getMessage());

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

			return [
				'success' => false,
				'job'     => $job->toArray(),
				'error'   => $e->getMessage(),
			];
		}
	}

	/**
	 * Retry failed jobs and return statistics.
	 *
	 * @return array{total_failed: int, retried: int, skipped: int}
	 */
	public function retryFailedJobsWithStats(): array
	{
		$failedJobs   = $this->jobRepository->fetchFailedJobs();
		$retriedCount = 0;
		$skippedCount = 0;

		foreach ($failedJobs as $job) {
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

		return [
			'total_failed' => count($failedJobs),
			'retried'      => $retriedCount,
			'skipped'      => $skippedCount,
		];
	}

	/**
	 * Perform database maintenance: prune old failed jobs and vacuum.
	 *
	 * @return array{pruned: int, vacuumed: bool}
	 */
	public function maintenance(int $daysOld = 30): array
	{
		$result = $this->jobRepository->maintenance($daysOld);

		$this->logger->info('Job queue maintenance completed', [
			'pruned_jobs' => $result['pruned'],
			'days_old'    => $daysOld,
			'vacuumed'    => $result['vacuumed'],
		]);

		return $result;
	}
}
