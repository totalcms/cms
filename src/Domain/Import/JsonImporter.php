<?php

namespace TotalCMS\Domain\Import;

use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Listener\IndexBuildListener;
use TotalCMS\Domain\Event\Payload\ImportEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

class JsonImporter
{
	private readonly LoggerInterface $logger;
	private string $collection;
	private bool $queueJobs = false;

	/** @var list<array{offset: int|string, id: string|null, reason: string}> */
	private array $skipped = [];
	private ?string $lastSkipReason = null;

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly ObjectFetcher $objectFetcher,
		private readonly ObjectImporter $objectImporter,
		private readonly IndexBuildListener $indexBuildListener,
		private readonly EventDispatcher $eventDispatcher,
		private readonly JobQueuer $jobQueuer,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::JsonImporter);
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

		// Suspend per-object index rebuilds AND `object.created`/`object.updated`
		// events during batch import. Per-object listeners that want
		// import-time notifications subscribe to `import.created` /
		// `import.updated` instead — those fire from ObjectImporter and
		// JobRunner regardless of suspension state.
		$this->indexBuildListener->suspendForCollection($collection);
		$this->eventDispatcher->suspendForImport($collection);

		$importCount   = 0;
		$createdIds    = [];
		$updatedIds    = [];
		$this->skipped = [];

		foreach ($records as $offset => $record) {
			$this->lastSkipReason = null;
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
				} elseif (!$imported) {
					$this->recordSkip($offset, $record, $this->lastSkipReason ?? 'skipped');
				}
			} catch (\Exception $exception) {
				$this->logger->error(
					sprintf('Error importing record #%s: %s', $offset, $exception->getMessage())
				);
				$this->recordSkip($offset, $record, $exception->getMessage());
			}
		}

		// Single index rebuild at end of import
		$this->eventDispatcher->dispatch(CoreEvent::IMPORT_COMPLETED, new ImportEventPayload($collection, $importCount, $createdIds, $updatedIds));

		return $importCount;
	}

	/**
	 * Per-record skip/failure details from the most recent import() call.
	 * Each entry has the record's offset in the source array, its id (when
	 * present), and a human-readable reason. Empty when every record imported
	 * cleanly. Lets callers (e.g. the CLI) surface why objects were skipped
	 * instead of failing silently.
	 *
	 * @return list<array{offset: int|string, id: string|null, reason: string}>
	 */
	public function getSkipped(): array
	{
		return $this->skipped;
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private function recordSkip(int|string $offset, array $record, string $reason): void
	{
		$this->skipped[] = [
			'offset' => $offset,
			'id'     => isset($record['id']) ? (string)$record['id'] : null,
			'reason' => $reason,
		];
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
			$this->lastSkipReason = $error;

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
			$this->lastSkipReason = 'Record has no id (required for update)';

			return false;
		}

		if (!$this->objectFetcher->existsObject($this->collection, (string)$record['id'])) {
			$this->logger->info(sprintf('Skipping update of record %s', $record['id']));
			$this->lastSkipReason = sprintf('No existing object with id %s to update', $record['id']);

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
