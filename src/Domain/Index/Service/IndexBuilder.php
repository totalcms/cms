<?php

namespace TotalCMS\Domain\Index\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\PasswordData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

class IndexBuilder
{
	/**
	 * Seconds before a build starts from which a file counts as changed
	 * during it. Re-reading a few recent files costs little; missing one
	 * writes a stale entry over a save.
	 */
	private const CHANGE_MARGIN = 2;

	private const STREAMING_THRESHOLD = 500; // Batch size for garbage collection during streaming.
	private const GC_BATCH_SIZE       = 100; // Batch size for garbage collection during streaming.

	private readonly LoggerInterface $logger;

	/**
	 * Ids skipped by the per-object catch blocks in the most recent
	 * buildIndex() call. Reset at the start of every call, so a caller reads
	 * it immediately after buildIndex() returns. Not readonly: unlike every
	 * other property here (each a fixed collaborator handed in once at
	 * construction), this one tracks per-call results and is reassigned on
	 * every buildIndex() call.
	 *
	 * Keyed on the id, so an object read again under the lock is not counted
	 * twice (a numeric id is an int key, as PHP casts it).
	 *
	 * @var array<string,true>
	 */
	private array $skippedIds = [];

	/**
	 * Objects written by the most recent buildIndex() call. Tracked because
	 * a streamed build writes straight to disk and returns an empty
	 * IndexData, so its count cannot come from the return value.
	 */
	private int $indexedCount = 0;

	public function __construct(
		private readonly IndexRepository $storage,
		private readonly ObjectFetcher $objectFetcher,
		private readonly SchemaFetcher $schemaFetcher,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CollectionSaver $collectionSaver,
		private readonly JobQueuer $jobQueuer,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->channelLogger(LogChannel::IndexBuilder);
	}

	/**
	 * Ids skipped (unreadable/unparseable) by the most recent buildIndex()
	 * call for any collection. Cleared at the start of each buildIndex() —
	 * read it right after calling.
	 *
	 * @return list<string>
	 */
	public function lastSkippedIds(): array
	{
		return array_map(strval(...), array_keys($this->skippedIds));
	}

	/**
	 * How many objects the most recent buildIndex() call put in the index,
	 * streamed builds included.
	 */
	public function lastIndexedCount(): int
	{
		return $this->indexedCount;
	}

	public function buildIndex(string $collection): IndexData
	{
		$this->skippedIds = [];

		$this->logger->info('Starting index build', [
			'collection' => $collection,
		]);

		// The files are read without the index lock, so saves keep appending
		// while a large collection is read. Anything written from here on has
		// a modified time at or after $since, and is read again under the lock
		// just before the index is written. $since starts a little early: a
		// save's file keeps the time its temp file was written, which can be
		// just before a rename that lands after the build read the old file.
		$since = time() - self::CHANGE_MARGIN;

		// Bypass cache to ensure we get the current filesystem state.
		// Cached IDs may include deleted objects or miss newly added ones.
		$objectIds  = $this->storage->fetchObjectIdsFromDisk($collection);
		$schema     = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$indexProps = $schema->index;

		$this->logger->info('Index build object count', [
			'collection'    => $collection,
			'object_count'  => count($objectIds),
			'threshold'     => self::STREAMING_THRESHOLD,
			'use_streaming' => count($objectIds) > self::STREAMING_THRESHOLD,
			'id_only'       => $this->isIdOnlyIndex($indexProps),
		]);

		// If the index only contains the id property, we can skip reading
		// every JSON file since the ID is derived from the filename.
		if ($this->isIdOnlyIndex($indexProps)) {
			$index = $this->buildIndexFromIds($collection);
		} elseif (count($objectIds) > self::STREAMING_THRESHOLD) {
			// Use streaming for large collections to minimize memory usage
			$index = $this->buildIndexStreaming($collection, $objectIds, $indexProps, $since);
		} else {
			$index = $this->buildIndexStandard($collection, $objectIds, $indexProps, $since);
		}

		// Reset totalObjects to the count on disk. It writes only when the
		// count is actually off (on object.created the metadata listener has
		// already recorded the new object), and it recounts under the meta
		// lock: the listing above may be stale by now.
		$this->collectionSaver->recountTotalObjects($collection);

		return $index;
	}

	/**
	 * Standard index building for small collections.
	 *
	 * @param array<string> $objectIds
	 * @param array<string> $indexProps
	 */
	private function buildIndexStandard(string $collection, array $objectIds, array $indexProps, int $since): IndexData
	{
		$entries = [];
		foreach ($objectIds as $id) {
			$entry = $this->readEntry($collection, $id, $indexProps);
			if ($entry !== null) {
				$entries[$id] = $entry;
			}
		}

		return $this->storage->withIndexLock($collection, function () use ($collection, $objectIds, $indexProps, $since, $entries): IndexData {
			[$changed, $gone] = $this->changesSince($collection, $objectIds, $since);
			foreach (array_keys($gone) as $id) {
				unset($entries[$id], $this->skippedIds[$id]);
			}
			foreach (array_keys($changed) as $id) {
				unset($entries[$id], $this->skippedIds[$id]);
				$entry = $this->readEntry($collection, (string)$id, $indexProps);
				if ($entry !== null) {
					$entries[$id] = $entry;
				}
			}

			$index = new IndexData();
			foreach ($entries as $entry) {
				$index->objects->push($entry);
			}
			$this->storage->saveIndex($collection, $index);
			$this->indexedCount = count($entries);

			return $index;
		});
	}

	/**
	 * Memory-efficient streaming index building for large collections.
	 * Entries go to a scratch file one per line while the objects are read,
	 * then are copied into the index under the lock, with the objects that
	 * changed meanwhile read again and the ones deleted meanwhile left out.
	 *
	 * @param array<string> $objectIds
	 * @param array<string> $indexProps
	 */
	private function buildIndexStreaming(string $collection, array $objectIds, array $indexProps, int $since): IndexData
	{
		$this->logger->info('Building index using streaming mode', [
			'collection'   => $collection,
			'object_count' => count($objectIds),
		]);

		// php://temp, not tmpfile(): hosts disable tmpfile() in PHP-FPM.
		// Like the index stream, it spills to disk past 2 MB.
		$scratch = fopen('php://temp/maxmemory:2097152', 'w+');
		if ($scratch === false) {
			throw new \RuntimeException('Failed to open a scratch file for the index build');
		}

		try {
			// Each line: the id as JSON, a tab, the entry as JSON. JSON
			// escapes tabs and newlines, so neither splits a line wrongly.
			$read = 0;
			foreach ($objectIds as $id) {
				$json = $this->readEncodedEntry($collection, $id, $indexProps);
				if ($json !== null) {
					fwrite($scratch, json_encode($id) . "\t" . $json . "\n");
				}
				unset($json);

				// Force garbage collection periodically
				if (++$read % self::GC_BATCH_SIZE === 0) {
					gc_collect_cycles();
				}
			}

			$this->indexedCount = $this->storage->withIndexLock(
				$collection,
				fn (): int => $this->copyScratchIntoIndex($collection, $scratch, $objectIds, $indexProps, $since),
			);
		} finally {
			fclose($scratch);
		}

		$this->logger->info('Streaming index build complete', [
			'collection'    => $collection,
			'indexed_count' => $this->indexedCount,
		]);

		// Return empty IndexData - the index was written directly to file
		// Callers needing the data should fetch it fresh from the repository
		return new IndexData();
	}

	/**
	 * Under the index lock: write the index from the scratch file, in its
	 * order, re-reading objects changed since $since in place, leaving out
	 * deleted ones and adding new ones at the end.
	 *
	 * @param resource $scratch
	 * @param array<string> $objectIds the ids the scratch file was built from
	 * @param array<string> $indexProps
	 *
	 * @return int the entries written
	 */
	private function copyScratchIntoIndex(string $collection, $scratch, array $objectIds, array $indexProps, int $since): int
	{
		[$changed, $gone] = $this->changesSince($collection, $objectIds, $since);
		foreach (array_keys($gone) as $id) {
			unset($this->skippedIds[$id]);
		}

		$handle  = $this->storage->openIndexStream($collection);
		$written = 0;
		$write   = function (string $json) use ($handle, &$written): void {
			$this->storage->writeIndexEntry($handle, $json, $written === 0);
			$written++;
		};

		rewind($scratch);
		while (($line = fgets($scratch)) !== false) {
			$tab = (int)strpos($line, "\t");
			$id  = (string)json_decode(substr($line, 0, $tab));
			if (isset($gone[$id])) {
				continue;
			}
			if (isset($changed[$id])) {
				// Read again, in the place it had.
				unset($changed[$id]);
				$json = $this->readEncodedEntry($collection, $id, $indexProps);
				if ($json !== null) {
					$write($json);
				}
				continue;
			}
			$write(rtrim(substr($line, $tab + 1), "\n"));
		}

		// New since the read, or unreadable then and changed since.
		foreach (array_keys($changed) as $id) {
			unset($this->skippedIds[$id]);
			$json = $this->readEncodedEntry($collection, (string)$id, $indexProps);
			if ($json !== null) {
				$write($json);
			}
		}

		$this->storage->closeIndexStream($handle, $collection);

		return $written;
	}

	/**
	 * What changed on disk since an unlocked read of $readIds began at
	 * $since. Called under the index lock. Keys are ids (a numeric id is an
	 * int key, as PHP casts it).
	 *
	 * @param array<string> $readIds
	 *
	 * @return array{array<string,true>, array<string,true>} ids to read again (changed or new), ids deleted
	 */
	private function changesSince(string $collection, array $readIds, int $since): array
	{
		// A file this process wrote or stat'd earlier must not report a
		// cached modified time.
		clearstatcache();
		$times   = $this->storage->fetchObjectFileTimes($collection);
		$read    = array_fill_keys($readIds, true);
		$changed = [];
		foreach ($times as $id => $time) {
			// Times are whole seconds, so a write in the same second as the
			// start counts as changed: reading it again is harmless.
			if ($time >= $since || !isset($read[$id])) {
				$changed[$id] = true;
			}
		}

		return [$changed, array_diff_key($read, $times)];
	}

	/**
	 * One object's index entry, read from disk, or null when it cannot be
	 * read (recorded in skippedIds).
	 *
	 * @param array<string> $indexProps
	 *
	 * @return array<string,mixed>|null
	 */
	private function readEntry(string $collection, string $id, array $indexProps): ?array
	{
		return $this->skipOnFailure($collection, $id, function () use ($collection, $id, $indexProps): array {
			// Bypass cache to ensure fresh data from filesystem
			$object = $this->objectFetcher->fetchObjectFromDisk($collection, $id);

			return $this->summarize($object, $indexProps, $id);
		});
	}

	/**
	 * readEntry() encoded as JSON for the streamed index, or null when the
	 * object cannot be read or encoded (invalid UTF-8): either way it is
	 * skipped, not the end of the build.
	 *
	 * @param array<string> $indexProps
	 */
	private function readEncodedEntry(string $collection, string $id, array $indexProps): ?string
	{
		return $this->skipOnFailure($collection, $id, function () use ($collection, $id, $indexProps): ?string {
			$entry = $this->readEntry($collection, $id, $indexProps);

			return $entry === null ? null : self::encodeEntry($entry);
		});
	}

	/**
	 * Run $read for one object; on failure record it in skippedIds, log it,
	 * and return null.
	 *
	 * @template T
	 *
	 * @param callable(): T $read
	 *
	 * @return T|null
	 */
	private function skipOnFailure(string $collection, string $id, callable $read): mixed
	{
		try {
			return $read();
		} catch (\Throwable $e) {
			// Skip objects that fail to load (e.g., type mismatches after schema changes)
			// Log the error but continue building index with remaining valid objects
			$this->skippedIds[$id] = true;
			$this->logger->warning('Skipping object during index build due to error', [
				'collection' => $collection,
				'object_id'  => $id,
				'error'      => $e->getMessage(),
				'exception'  => $e::class,
			]);

			return null;
		}
	}

	/**
	 * One index entry as JSON, as the streamed index stores it.
	 *
	 * @param array<string,mixed> $entry
	 */
	private static function encodeEntry(array $entry): string
	{
		$json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode index entry: ' . json_last_error_msg());
		}

		return $json;
	}

	/**
	 * Check if the index only contains the id property.
	 *
	 * @param array<string> $indexProps
	 */
	private function isIdOnlyIndex(array $indexProps): bool
	{
		return $indexProps === ['id'] || $indexProps === [];
	}

	/**
	 * Build an index using only object IDs from filenames.
	 * Skips reading/parsing JSON files since only the id property is needed,
	 * so the whole build is a directory listing and runs under the lock.
	 */
	private function buildIndexFromIds(string $collection): IndexData
	{
		return $this->storage->withIndexLock($collection, function () use ($collection): IndexData {
			$objectIds = $this->storage->fetchObjectIdsFromDisk($collection);

			$this->logger->info('Building id-only index from filenames', [
				'collection'   => $collection,
				'object_count' => count($objectIds),
			]);

			$index = new IndexData();
			foreach ($objectIds as $id) {
				$index->objects->push(['id' => $id]);
			}

			$this->storage->saveIndex($collection, $index);
			$this->indexedCount = count($objectIds);

			return $index;
		});
	}

	/**
	 * Append a new object to the existing index for immediate visibility.
	 * This is more efficient than rebuilding the entire index.
	 */
	public function appendObjectToIndex(string $collection, ObjectData $object): void
	{
		// Read from disk under the lock: a cached copy may predate another
		// process's append, and writing it back would drop that entry.
		$this->storage->withIndexLock($collection, function () use ($collection, $object): void {
			$index = $this->storage->fetchIndexFromDisk($collection) ?? new IndexData();

			// Remove existing entry with same ID (for updates) and append new one
			$index->objects = $index->objects->reject(fn ($item): bool => $item['id'] === $object->id);
			$index->objects->push($object->toArrayWithoutPasswords());

			$this->storage->saveIndex($collection, $index);
		});
	}

	/**
	 * The index entry for one object: its indexed properties plus the id.
	 * Password properties never go in, even when a schema lists one in its
	 * index — the index is served by the public `/index` and query endpoints.
	 *
	 * @param array<string> $indexProps
	 *
	 * @return array<string,mixed>
	 */
	private function summarize(ObjectData $object, array $indexProps, string $id): array
	{
		$summary = $object->properties
			->reject(fn ($value, $key): bool => !in_array($key, $indexProps, true) || $value instanceof PasswordData)
			->map(fn ($property): mixed => $property->transform());
		$summary->put('id', $id);

		return $summary->toArray();
	}

	/**
	 * Remove an object from the index.
	 */
	public function removeObjectFromIndex(string $collection, string $objectId): void
	{
		// Same as appendObjectToIndex(): from disk, under the lock.
		$this->storage->withIndexLock($collection, function () use ($collection, $objectId): void {
			$index = $this->storage->fetchIndexFromDisk($collection);
			if (!$index instanceof IndexData) {
				return; // No index exists, nothing to remove
			}

			$index->objects = $index->objects->reject(fn ($item): bool => $item['id'] === $objectId);

			$this->storage->saveIndex($collection, $index);
		});
	}

	public function smartBuildIndex(string $collection, ?ObjectData $newObject = null): void
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof CollectionData) {
			throw new \DomainException(sprintf('Collection %s not found', $collection));
		}
		$queueReindex = $collectionData->queueRebuildOnSave;

		// If we have a new object and queueRebuildOnSave is enabled,
		// append the object immediately for visibility, then queue full rebuild
		if ($queueReindex && $newObject instanceof ObjectData) {
			$this->appendObjectToIndex($collection, $newObject);
			$this->jobQueuer->queueBuildIndex($collection);
		} elseif ($queueReindex) {
			// No new object provided, just queue the rebuild
			$this->jobQueuer->queueBuildIndex($collection);
		} else {
			// Immediate rebuild
			$this->buildIndex($collection);
		}
	}
}
