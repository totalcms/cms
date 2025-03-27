<?php

namespace TotalCMS\Domain\Import;

use League\Csv\Reader;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Factory\LoggerFactory;

final class CsvImporter
{
	private LoggerInterface $logger;

	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private ObjectFetcher $objectFetcher,
		private ObjectImporter $objectImporter,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('csv_importer.log')
			->createLogger();
	}

	public function import(string $collection, UploadedFileInterface $file): int
	{
		if (!$this->collectionFetcher->collectionExists($collection)) {
			$error = sprintf('Collection %s does not exist', $collection);
			$this->logger->error($error);
			throw new \InvalidArgumentException($error);
		}

		$importCount = 0;

		// Take the uploaded file and update object with related data
		$csv = Reader::createFromString((string)$file->getStream());
		$csv->setHeaderOffset(0);

		foreach ($csv->getRecords() as $offset => $record) {
			try {
				if (!isset($record['id']) || $this->objectFetcher->existsObject($collection, (string)$record['id'])) {
					$this->logger->info(sprintf('Skipping import of record %s at row %s', $record['id'], $offset));
					continue;
				}

				// Save the object but do not rebuild the index, we do that at the end
				$this->objectImporter->importObject($collection,  $record);
				$this->logger->info(sprintf('Imported record: %s', $record['id']));
				$this->logger->debug('Imported record', $record);

				$importCount++;
			} catch (\Exception $exception) {
				$this->logger->error(
					sprintf('Error importing record at row %s: %s', $offset, $exception->getMessage())
				);
			}
		}

		return $importCount;
	}
}
