<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Index\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Data\IndexCheckResult;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Storage\Exception\CorruptedStorageFileException;

/**
 * Compare a collection's index with its object files, read-only: every
 * object must parse, the index must list exactly the objects on disk, and the
 * collection's object count must match. `tcms repair:index --check` reports
 * what this finds before a rebuild paves over it.
 *
 * Reads every object, so it costs about as much as a rebuild.
 */
class IndexChecker
{
	public function __construct(
		private readonly IndexRepository $storage,
		private readonly ObjectFetcher $objectFetcher,
		private readonly CollectionFetcher $collectionFetcher,
	) {
	}

	public function check(string $collection): IndexCheckResult
	{
		$objectIds  = $this->storage->fetchObjectIdsFromDisk($collection);
		$unreadable = [];
		foreach ($objectIds as $id) {
			try {
				$this->objectFetcher->fetchObjectFromDisk($collection, $id);
			} catch (\Throwable) {
				$unreadable[] = $id;
			}
		}

		$indexReadable = true;
		$indexIds      = null;
		try {
			$index = $this->storage->fetchIndexFromDisk($collection);
			if ($index !== null) {
				$indexIds = array_map(
					static fn (mixed $entry): string => is_array($entry) ? (string)($entry['id'] ?? '') : '',
					array_values($index->objects->toArray()),
				);
			}
		} catch (CorruptedStorageFileException) {
			$indexReadable = false;
		}

		$collectionData = $this->collectionFetcher->fetchCollection($collection);

		return new IndexCheckResult(
			collection: $collection,
			objects: count($objectIds),
			indexed: $indexIds === null ? null : count($indexIds),
			indexReadable: $indexReadable,
			recordedCount: $collectionData instanceof CollectionData && $collectionData->totalObjects >= 0 ? $collectionData->totalObjects : null,
			unreadable: $unreadable,
			missingFiles: $indexIds === null ? [] : array_values(array_diff($indexIds, $objectIds)),
			notIndexed: $indexIds === null ? [] : array_values(array_diff($objectIds, $indexIds)),
		);
	}
}
