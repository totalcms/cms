<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ImportEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Domain\Property\Data\SlugData;

/**
 * Write a batch of parsed records into a collection.
 *
 * This is the pipeline every record-file importer shares once it has turned
 * its format into an array of records: suspend per-object index rebuilds and
 * `object.created` / `object.updated` events for the batch, create or update
 * each record (directly, or through the job queue), keep a skip list with
 * reasons, and fire `import.completed` once so the index rebuilds a single
 * time. CsvImporter and JsonImporter each carried a copy of it — and they
 * had drifted on whether incoming ids were slugified.
 *
 * Listeners that want per-record import notifications subscribe to
 * `import.created` / `import.updated`, which ObjectImporter fires regardless
 * of the suspension.
 */
final class RecordBatchImporter
{
	private string $collection      = '';
	private ?string $lastSkipReason = null;

	public function __construct(
		private readonly ObjectFetcher $objectFetcher,
		private readonly ObjectImporter $objectImporter,
		private readonly EventDispatcher $eventDispatcher,
		private readonly JobQueuer $jobQueuer,
	) {
	}

	/**
	 * @param list<array<string,mixed>> $records
	 * @param bool                      $update    update existing objects instead of creating new ones
	 * @param bool                      $queueJobs queue a job per record instead of writing directly
	 */
	public function import(string $collection, array $records, bool $update, bool $queueJobs, LoggerInterface $logger): ImportBatchResult
	{
		$this->collection = $collection;

		$this->eventDispatcher->suspendIndexRebuild($collection);
		$this->eventDispatcher->suspendForImport($collection);

		$imported   = 0;
		$createdIds = [];
		$updatedIds = [];
		$skipped    = [];

		foreach ($records as $offset => $record) {
			$this->lastSkipReason = null;
			try {
				$done = $update
					? $this->updateRecord($record, $queueJobs, $logger)
					: $this->createRecord($record, $queueJobs, $logger);

				if (!$done) {
					$skipped[] = $this->skip($offset, $record, $this->lastSkipReason ?? 'skipped');
					continue;
				}

				// Records without an id still count — the schema autogen assigns
				// one during save, so only the id lists leave them out.
				$imported++;
				if (isset($record['id'])) {
					$id = SlugData::slugify((string)$record['id']);
					if ($update) {
						$updatedIds[] = $id;
					} else {
						$createdIds[] = $id;
					}
				}
			} catch (\Exception $exception) {
				$logger->error(sprintf('Error importing record %s: %s', $offset, $exception->getMessage()));
				$skipped[] = $this->skip($offset, $record, $exception->getMessage());
			}
		}

		// One index rebuild for the whole batch.
		$this->eventDispatcher->dispatch(CoreEvent::IMPORT_COMPLETED, new ImportEventPayload($collection, $imported, $createdIds, $updatedIds));

		return new ImportBatchResult($imported, $skipped);
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private function createRecord(array $record, bool $queueJobs, LoggerInterface $logger): bool
	{
		// Slugify the id so every format lands the same record on the same
		// object. Records may omit `id` — ObjectSaver autogenerates one when
		// the schema allows it (and rejects the record when it does not).
		if (isset($record['id'])) {
			$record['id'] = SlugData::slugify((string)$record['id']);
		}

		if (isset($record['id']) && $this->objectFetcher->existsObject($this->collection, (string)$record['id'])) {
			$this->lastSkipReason = sprintf('Object with id %s already exists in %s', $record['id'], $this->collection);
			$logger->warning($this->lastSkipReason);

			return false;
		}

		$label = isset($record['id']) ? (string)$record['id'] : '(autogen id)';
		if ($queueJobs) {
			$this->jobQueuer->queueImport($this->collection, $record);
			$logger->info(sprintf('Queued record for import: %s', $label));
		} else {
			// Save without rebuilding the index; the batch rebuilds once at the end.
			$this->objectImporter->importObject($this->collection, $record);
			$logger->info(sprintf('Imported record: %s', $label));
		}
		$logger->debug('Imported record', $record);

		return true;
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private function updateRecord(array $record, bool $queueJobs, LoggerInterface $logger): bool
	{
		if (isset($record['id'])) {
			$record['id'] = SlugData::slugify((string)$record['id']);
		}

		if (!isset($record['id'])) {
			$this->lastSkipReason = 'Record has no id (required for update)';
			$logger->info('Skipping update of record without id');

			return false;
		}

		if (!$this->objectFetcher->existsObject($this->collection, (string)$record['id'])) {
			$this->lastSkipReason = sprintf('No existing object with id %s to update', $record['id']);
			$logger->info(sprintf('Skipping update of record %s', $record['id']));

			return false;
		}

		if ($queueJobs) {
			$this->jobQueuer->queueUpdate($this->collection, $record);
			$logger->info(sprintf('Queued record for update: %s', $record['id']));
		} else {
			$this->objectImporter->updateObject($this->collection, $record);
			$logger->info(sprintf('Updated record: %s', $record['id']));
		}
		$logger->debug('Updated record', $record);

		return true;
	}

	/**
	 * @param array<string,mixed> $record
	 *
	 * @return array{offset: int|string, id: string|null, reason: string}
	 */
	private function skip(int|string $offset, array $record, string $reason): array
	{
		return [
			'offset' => $offset,
			'id'     => isset($record['id']) ? (string)$record['id'] : null,
			'reason' => $reason,
		];
	}
}
