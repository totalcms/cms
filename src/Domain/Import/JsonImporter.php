<?php

namespace TotalCMS\Domain\Import;

use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Import a JSON upload — an array of records — into a collection. Parsing is
 * the only thing here; the writing is {@see RecordBatchImporter}.
 */
class JsonImporter
{
	private readonly LoggerInterface $logger;
	private bool $queueJobs = false;

	/** @var list<array{offset: int|string, id: string|null, reason: string}> */
	private array $skipped = [];

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly RecordBatchImporter $batch,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::JsonImporter);
	}

	public function queueJobs(): void
	{
		$this->queueJobs = true;
	}

	public function import(string $collection, UploadedFileInterface $file, bool $updateObject = false): int
	{
		if (!$this->collectionFetcher->collectionExists($collection)) {
			$error = sprintf('Collection does not exist: %s', $collection);
			$this->logger->error($error);
			throw new \InvalidArgumentException($error);
		}

		$records = json_decode((string)$file->getStream(), true);

		if (!is_array($records) || !array_reduce($records, fn ($carry, $item): bool => $carry && is_array($item), true)) {
			$error = 'Invalid JSON structure for import: expected an array of records';
			$this->logger->error($error);
			throw new \InvalidArgumentException($error);
		}

		$result        = $this->batch->import($collection, array_values($records), $updateObject, $this->queueJobs, $this->logger);
		$this->skipped = $result->skipped;

		return $result->imported;
	}

	/**
	 * @return list<array{offset: int|string, id: string|null, reason: string}>
	 */
	public function getSkipped(): array
	{
		return $this->skipped;
	}
}
