<?php

namespace TotalCMS\Domain\Import;

use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Listener\IndexBuildListener;
use TotalCMS\Domain\Event\Payload\ImportEventPayload;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Factory\LoggerFactory;

class JsonImporter
{
	private readonly LoggerInterface $logger;
	private string $collection;
	private bool $queueJobs = false;

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly ObjectFetcher $objectFetcher,
		private readonly ObjectImporter $objectImporter,
		private readonly IndexBuildListener $indexBuildListener,
		private readonly EventDispatcher $eventDispatcher,
		private readonly JobQueuer $jobQueuer,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('importer.log')->createLogger('json-importer');
	}

	public function queueJobs(): void
	{
		$this->queueJobs = true;
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

		$records = json_decode((string)$file->getStream(), true);

		if (!is_array($records) || !array_reduce($records, fn ($carry, $item): bool => $carry && is_array($item), true)) {
			$error = 'Invalid JSON structure for import: expected an array of records';
			$this->logger->error($error);
			throw new \InvalidArgumentException($error);
		}

		// Suspend per-object index rebuilds during batch import
		$this->indexBuildListener->suspendForCollection($collection);

		$importCount = 0;
		$createdIds  = [];
		$updatedIds  = [];

		foreach ($records as $offset => $record) {
			try {
				$imported = $updateObject ?
					$this->updateObject($record) :
					$this->importNewObject($record);

				if ($imported && isset($record['id'])) {
					$id = (string)$record['id'];
					if ($updateObject) {
						$updatedIds[] = $id;
					} else {
						$createdIds[] = $id;
					}
					$importCount++;
				}
			} catch (\Exception $exception) {
				$this->logger->error(
					sprintf('Error importing record #%s: %s', $offset, $exception->getMessage())
				);
			}
		}

		// Single index rebuild at end of import
		$this->eventDispatcher->dispatch('import.completed', new ImportEventPayload($collection, $importCount, $createdIds, $updatedIds));

		return $importCount;
	}

	/**
	 * @param array<string,mixed> $record
	 */
	public function importNewObject(array $record): bool
	{
		// if (!isset($record['id'])) {
		// 	$this->logger->warning('Skipping import of record without ID');

		// 	return false;
		// }
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
	public function updateObject(array $record): bool
	{
		if (!isset($record['id'])) {
			$this->logger->info('Skipping update of record without ID');

			return false;
		}

		if (!$this->objectFetcher->existsObject($this->collection, (string)$record['id'])) {
			$this->logger->info(sprintf('Skipping update of record %s', $record['id']));

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
