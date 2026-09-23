<?php

namespace TotalCMS\Domain\Import;

use League\Csv\Reader;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Import a CSV upload into a collection: one record per row, headers as
 * property names. Parsing is the only thing here; the writing is
 * {@see RecordBatchImporter}.
 */
class CsvImporter
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
		$this->logger = $loggerFactory->channelLogger(LogChannel::CsvImporter);
	}

	public function queueJobs(): void
	{
		$this->queueJobs = true;
	}

	/**
	 * Rows as associative arrays: trimmed, columns with an empty header
	 * dropped, rows with nothing in them dropped.
	 *
	 * @param Reader<array<string,string>> $csv
	 *
	 * @return list<array<string,string>>
	 */
	public static function cleanCsvData(Reader $csv): array
	{
		$headers = array_filter($csv->getHeader(), fn (string $header): bool => trim($header) !== '');

		$cleaned = [];
		foreach ($csv->getRecords() as $record) {
			$filtered = array_intersect_key(array_map(trim(...), $record), array_flip($headers));

			if (array_filter($filtered)) {
				$cleaned[] = $filtered;
			}
		}

		return $cleaned;
	}

	public function import(string $collection, UploadedFileInterface $file, bool $updateObject = false): int
	{
		if (!$this->collectionFetcher->collectionExists($collection)) {
			$error = sprintf('Collection does not exist: %s', $collection);
			$this->logger->error($error);
			throw new \InvalidArgumentException($error);
		}

		$this->logger->info(sprintf('Starting CSV import for collection: %s', $collection));

		$csv = Reader::fromString((string)$file->getStream());
		$csv->setHeaderOffset(0);
		$records = self::cleanCsvData($csv);
		$this->logger->info(sprintf('Found %d records to import', count($records)));

		$result        = $this->batch->import($collection, $records, $updateObject, $this->queueJobs, $this->logger);
		$this->skipped = $result->skipped;

		$this->logger->info(sprintf('CSV import completed. Successfully imported %d of %d records into collection: %s', $result->imported, count($records), $collection));

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
