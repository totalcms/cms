<?php

namespace TotalCMS\Domain\Import;

use League\Csv\Reader;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Domain\Property\Data\SlugData;
use TotalCMS\Factory\LoggerFactory;

class CsvImporter
{
	private readonly LoggerInterface $logger;
	private string $collection;
	private bool $queueJobs = false;

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly ObjectFetcher $objectFetcher,
		private readonly ObjectImporter $objectImporter,
		private readonly IndexBuilder $indexBuilder,
		private readonly JobQueuer $jobQueuer,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('importer.log')->createLogger('csv-importer');
	}

	public function queueJobs(): void
	{
		$this->queueJobs = true;
	}

	/**
	 * Clean up CSV data by removing empty headers and rows with no data.
	 *
	 * @param Reader<array<string,string>> $csv
	 *
	 * @return array<int, array<string, mixed>> Cleaned CSV records
	 */
	public static function cleanCsvData(Reader $csv): array
	{
		$headers = $csv->getHeader(); // Get the headers
		$records = $csv->getRecords(); // Get the records

		// Filter out empty headers
		$headers = array_filter($headers, fn (string $header): bool => (trim($header) !== ''));

		$cleanedRecords = [];
		foreach ($records as $record) {
			// Trim all values in the record
			$trimmedRecord = array_map(trim(...), $record);

			// Remove columns with empty headers
			$filteredRecord = array_intersect_key($trimmedRecord, array_flip($headers));

			// Skip rows where all values are empty
			if (array_filter($filteredRecord)) {
				$cleanedRecords[] = $filteredRecord;
			}
		}

		return $cleanedRecords;
	}

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function import(string $collection, UploadedFileInterface $file, bool $updateObject = false): int
	{
		if (!$this->collectionFetcher->collectionExists($collection)) {
			$error = sprintf('Collection does not exist: %s', $collection);
			$this->logger->error($error);
			throw new \InvalidArgumentException($error);
		}
		$this->collection = $collection;

		$this->logger->info(sprintf('Starting CSV import for collection: %s', $collection));
		$importCount = 0;

		// Take the uploaded file and update object with related data
		$csv = Reader::fromString((string)$file->getStream());
		$csv->setHeaderOffset(0);

		$cleanedRecords = self::cleanCsvData($csv);
		$totalRecords   = count($cleanedRecords);
		$this->logger->info(sprintf('Found %d records to import', $totalRecords));

		foreach ($cleanedRecords as $offset => $record) {
			try {
				$imported = $updateObject ?
					$this->updateObject($offset, $record) :
					$this->importNewObject($offset, $record);

				if ($imported) {
					$importCount++;
				}
			} catch (\Exception $exception) {
				$this->logger->error(
					sprintf('Error importing record at row %s: %s', $offset, $exception->getMessage())
				);
			}
		}

		// Rebuild index
		$this->indexBuilder->buildIndex($collection);

		$this->logger->info(sprintf('CSV import completed. Successfully imported %d of %d records into collection: %s', $importCount, $totalRecords, $collection));

		return $importCount;
	}

	/**
	 * @param array<string,mixed> $record
	 */
	public function importNewObject(int $offset, array $record): bool
	{
		// Slugify ID to ensure consistent format
		if (isset($record['id'])) {
			$record['id'] = SlugData::slugify((string)$record['id']);
		}

		if ($this->objectFetcher->existsObject($this->collection, (string)$record['id'])) {
			$error = sprintf('Object with id %s already exists in %s', $record['id'], $this->collection);
			$this->logger->warning($error);

			return false;
		}

		if ($this->queueJobs) {
			// Add job to queue
			$this->jobQueuer->queueImport($this->collection, $record);
			$this->logger->info(sprintf('Queued record for import: %s', $record['id']));
		} else {
			// Save the object but do not rebuild the index, we do that at the end
			$this->objectImporter->importObject($this->collection, $record);
			$this->logger->info(sprintf('Imported record: %s', $record['id']));
		}
		$this->logger->debug('Imported record', $record);

		return true;
	}

	/**
	 * @param array<string,mixed> $record
	 */
	public function updateObject(int $offset, array $record): bool
	{
		// Slugify ID to ensure consistent format
		if (isset($record['id'])) {
			$record['id'] = SlugData::slugify((string)$record['id']);
		}

		if (!isset($record['id']) || !$this->objectFetcher->existsObject($this->collection, (string)$record['id'])) {
			$this->logger->info(sprintf('Skipping update of record (%s) at row %s', $record['id'] ?? 'no-id', $offset));

			return false;
		}

		if ($this->queueJobs) {
			// Add job to queue
			$this->jobQueuer->queueUpdate($this->collection, $record);
			$this->logger->info(sprintf('Queued record for update: %s', $record['id']));
		} else {
			// Save the object but do not rebuild the index, we do that at the end
			$this->objectImporter->updateObject($this->collection, $record);
			$this->logger->info(sprintf('Updated record: %s', $record['id']));
		}
		$this->logger->debug('Updated record', $record);

		return true;
	}
}
