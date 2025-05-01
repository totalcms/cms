<?php

namespace TotalCMS\Domain\JobQueue\Service;

use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LoggerFactory;

final class JobRunner
{
	private LoggerInterface $logger;

	public function __construct(
		private JobRepository $jobRepository,
		private ObjectImporter $objectImporter,
		private IndexBuilder $indexBuilder,
		LoggerFactory $loggerFactory,
	){
		$this->logger = $loggerFactory
		->addFileHandler('jobs.log')
		->createLogger();
	}

	public function retryFailedJobs(): void
	{
		// TODO: Implement retryFailedJobs()
	}

	public function processPendingJobs(): void
	{
		while ($this->jobRepository->hasPendingJobs()) {
			$this->processNextJob();
		}
		$this->logger->info('Processed all pending jobs');
	}

	public function processNextJob(): void
	{
		$job = $this->jobRepository->fetchNextJob();
		try {
			$this->processJob($job);
			$this->jobRepository->markDone($job);
			$this->logger->info('Job processed successfully', $job->toArray());
		} catch (\Throwable $e) {
			$this->jobRepository->markFailed($job, $e->getMessage());
			$this->logger->error('Job failed: ' . $e->getMessage(), $job->toArray());
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

	private function processExportJob(JobData $job): void
	{
		// TODO: Implement processExportJob
	}
}
