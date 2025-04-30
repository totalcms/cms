<?php

namespace TotalCMS\Domain\Import;

use League\Csv\Reader;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Factory\LoggerFactory;

final class CsvImporter
{
	private LoggerInterface $logger;
	private string $collection;
	private bool $processNow = false;

	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private ObjectFetcher $objectFetcher,
		private ObjectImporter $objectImporter,
		private JobQueuer $jobQueuer,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('csv_importer.log')
			->createLogger();
	}

	public function processNow(): void
	{
		$this->processNow = true;
	}

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function import(string $collection, UploadedFileInterface $file, bool $updateObject = false): int
	{
		if (!$this->collectionFetcher->collectionExists($collection)) {
			$error = sprintf('Collection %s does not exist', $collection);
			$this->logger->error($error);
			throw new \InvalidArgumentException($error);
		}
		$this->collection = $collection;

		$importCount = 0;

		// Take the uploaded file and update object with related data
		$csv = Reader::createFromString((string)$file->getStream());
		$csv->setHeaderOffset(0);

		foreach ($csv->getRecords() as $offset => $record) {
			try {
				$imported = $updateObject === true ?
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

		return $importCount;
	}

	/**
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 * @param array<string,mixed> $record
	 */
	public function importNewObject(int $offset, array $record): bool
	{
		if (!isset($record['id']) || $this->objectFetcher->existsObject($this->collection, (string)$record['id'])) {
			$this->logger->info(sprintf('Skipping import of record (%s) at row %s', $record['id'], $offset));
			return false;
		}

		if ($this->processNow) {
			// Save the object but do not rebuild the index, we do that at the end
			$this->objectImporter->importObject($this->collection, $record);
			$this->logger->info(sprintf('Imported record: %s', $record['id']));
		} else {
			// Add job to queue
			$this->jobQueuer->queueImport($this->collection, $record);
			$this->logger->info(sprintf('Queued record for import: %s', $record['id']));
		}
		$this->logger->debug('Imported record', $record);

		return true;
	}

	/**
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 * @param array<string,mixed> $record
	 */
	public function updateObject(int $offset, array $record): bool
	{
		if (!isset($record['id']) || !$this->objectFetcher->existsObject($this->collection, (string)$record['id'])) {
			$this->logger->info(sprintf('Skipping update of record (%s) at row %s', $record['id'], $offset));
			return false;
		}

		if ($this->processNow) {
			// Save the object but do not rebuild the index, we do that at the end
			$this->objectImporter->updateObject($this->collection, $record);
			$this->logger->info(sprintf('Updated record: %s', $record['id']));
		} else {
			// Add job to queue
			$this->jobQueuer->queueUpdate($this->collection, $record);
			$this->logger->info(sprintf('Queued record for update: %s', $record['id']));
		}
		$this->logger->debug('Updated record', $record);

		return true;
	}
}
