<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Backup\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFileCodec;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Puts a snapshot back as the live record.
 *
 * A restore is an ordinary update: the snapshot is decoded and handed to
 * ObjectUpdater, which re-encodes it in the collection's current on-disk
 * format, rebuilds the index through the normal event cascade, and — because
 * `object.updated` carries the pre-restore state as `previous` — snapshots
 * what the restore replaced. Undoing a restore is therefore just another
 * restore, one entry back.
 *
 * Restoring a deleted object works the same way: ObjectUpdater tolerates a
 * missing prior state, so the record simply reappears.
 *
 * Snapshots may be JSON (the lifecycle writes those) or Markdown (sync
 * keeps the on-disk file, which is `.md` for a markdown collection). Either
 * decodes through the same codec the repository uses, so a `.md` snapshot's
 * body lands back in the right property.
 */
readonly class ObjectRestorer
{
	public function __construct(
		private BackupStore $store,
		private ObjectUpdater $updater,
		private ObjectFileCodec $codec,
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/**
	 * @param string $file a bare snapshot filename from BackupStore::listObjectSnapshots()
	 *
	 * @throws \UnexpectedValueException when the snapshot is missing or cannot be decoded
	 */
	public function restore(string $collection, string $id, string $file): ObjectData
	{
		$contents = $this->store->readObjectSnapshot($collection, $id, $file);
		if ($contents === null) {
			throw new \UnexpectedValueException("Snapshot '{$file}' not found for {$collection}/{$id}");
		}

		$data = $this->codec->decode($contents, $this->formatOf($file), $this->bodyPropertyOf($collection));

		// A snapshot is authoritative about which record it belongs to.
		$data['id'] = $id;

		return $this->updater->updateObject($collection, $id, $data);
	}

	private function formatOf(string $file): string
	{
		return str_ends_with($file, '.md') ? CollectionData::FORMAT_MARKDOWN : CollectionData::FORMAT_JSON;
	}

	/**
	 * Only consulted for a `.md` snapshot; a schema that can't be read means
	 * the body has nowhere to go, which the codec handles by keeping the
	 * frontmatter alone.
	 */
	private function bodyPropertyOf(string $collection): ?string
	{
		try {
			return $this->codec->bodyProperty($this->schemaFetcher->fetchSchemaForCollection($collection)->properties);
		} catch (\Throwable) {
			return null;
		}
	}
}
