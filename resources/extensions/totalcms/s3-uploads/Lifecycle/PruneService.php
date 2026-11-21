<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Lifecycle;

use TotalCMS\Bundled\S3Uploads\S3\S3Client;
use TotalCMS\Bundled\S3Uploads\S3\S3Exception;
use TotalCMS\Bundled\S3Uploads\S3\S3Settings;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * Removes files from the bucket that no object points at: an upload whose tab
 * was closed before it was committed, the files of a deleted collection, a
 * file a failed cleanup left behind.
 *
 * It lists everything under the prefix and subtracts every key an object
 * references. What is left, and older than a day, is an orphan. The age rule
 * matters: a file that is uploading right now has no pointer yet.
 *
 * Deleting on a wrong picture of the references would destroy live files, so
 * the run is careful to the point of refusing: no prefix, no run; one object
 * that cannot be read, and nothing is deleted.
 */
final readonly class PruneService
{
	private const SAMPLE_SIZE = 50;

	/** @param \Closure(): int $now Unix time */
	public function __construct(
		private S3Settings $settings,
		private S3Client $client,
		private S3Fields $fields,
		private CollectionLister $collections,
		private IndexRepository $index,
		private ObjectFetcher $objects,
		private \Closure $now,
	) {
	}

	/**
	 * @param bool $delete           false reports only
	 * @param int  $olderThanSeconds an unreferenced file younger than this is left alone
	 * @param int  $max              stop after deleting this many
	 * @param bool $wholeBucket      allow a run with no prefix, which treats the entire bucket as this site's
	 *
	 * @throws S3Exception when the bucket cannot be listed
	 */
	public function run(bool $delete, int $olderThanSeconds = 86400, int $max = 1000, bool $wholeBucket = false): PruneReport
	{
		if ($this->settings->prefix === '' && !$wholeBucket) {
			return new PruneReport(refused: true);
		}

		$problems   = [];
		$referenced = $this->referencedKeys($problems);

		// A reference that could not be read would make its file look like an orphan.
		$downgraded = $delete && $problems !== [];
		$delete     = $delete && $problems === [];

		$cutoff  = ($this->now)() - max(0, $olderThanSeconds);
		$listed  = 0;
		$orphans = 0;
		$deleted = 0;
		$bytes   = 0;
		$sample  = [];
		$capped  = false;

		foreach ($this->client->listObjects($this->settings->prefix) as $object) {
			$listed++;
			if (isset($referenced[$object->key]) || $object->lastModified > $cutoff) {
				continue;
			}

			$orphans++;
			if (count($sample) < self::SAMPLE_SIZE) {
				$sample[] = $object->key;
			}

			if (!$delete) {
				$bytes += $object->size;

				continue;
			}
			if ($deleted >= $max) {
				$capped = true;

				continue;
			}

			$this->client->deleteObject($object->key);
			$deleted++;
			$bytes += $object->size;
		}

		return new PruneReport($listed, count($referenced), $orphans, $deleted, $bytes, $sample, $capped, $downgraded, $problems);
	}

	/**
	 * Every key an object points at, read from disk one object at a time.
	 *
	 * @param list<string> $problems filled with what could not be read
	 *
	 * @return array<string,true>
	 */
	private function referencedKeys(array &$problems): array
	{
		$keys = [];

		try {
			$collections = $this->collections->listAllCollections();
		} catch (\Throwable) {
			$problems[] = 'The collections could not be listed.';

			return $keys;
		}

		foreach ($collections as $collection) {
			$id     = $collection->id;
			$fields = $this->fields->forCollection($id);
			if ($fields === []) {
				continue;
			}

			try {
				$objectIds = $this->index->fetchObjectIdsFromDisk($id);
			} catch (\Throwable) {
				$problems[] = "The objects of {$id} could not be listed.";

				continue;
			}

			foreach ($objectIds as $objectId) {
				try {
					$stored = $this->objects->fetchObjectFromDisk($id, (string)$objectId)->toArray();
				} catch (\Throwable) {
					$problems[] = "{$id}/{$objectId} could not be read.";

					continue;
				}

				foreach ($fields as $property => $field) {
					foreach (S3Fields::pointers($stored[$property] ?? null, $field['field']) as $pointer) {
						$keys[$pointer->key] = true;
					}
				}
				unset($stored);
			}
		}

		return $keys;
	}
}
