<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Backup\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectFileCodec;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\FolderData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\PasswordData;
use TotalCMS\Domain\Property\Data\VideoData;
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
 * After the record is back, every file it names gets its file back too, when
 * the file history kept it ({@see FileBackupStore}): image and file fields,
 * those inside a card or a deck item, gallery pictures and depot files. The
 * file a restore displaces is kept in turn, so undoing a restore brings that
 * back as well. A file that was never kept is reported, not invented.
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
		private FileBackupStore $files,
		private ObjectFetcher $objects,
	) {
	}

	/**
	 * @param string $file a bare snapshot filename from BackupStore::listObjectSnapshots()
	 *
	 * @throws \UnexpectedValueException when the snapshot is missing or cannot be decoded
	 */
	public function restore(string $collection, string $id, string $file): ObjectData
	{
		return $this->restoreWithFiles($collection, $id, $file)['object'];
	}

	/**
	 * Restore, and say what happened to the record's files.
	 *
	 * `files` lists only what an operator needs to hear about: a file that came
	 * back (`restored`), and a file the record names that is neither on disk
	 * nor in the file history (`missing`). `path` is the folder under the
	 * property the file belongs in: a card child, a deck item's child, a depot
	 * folder; empty for the property's own.
	 *
	 * @param string $file a bare snapshot filename from BackupStore::listObjectSnapshots()
	 * @param string|null $property      restore this one top-level property and leave the rest of the record as it is now; the object must exist
	 * @param bool        $keepPasswords keep the password the record has now. A snapshot of a user holds the old hash, and putting it back would make an old password work again. A deleted object has no current password, so it keeps the snapshot's.
	 *
	 * @throws \UnexpectedValueException when the snapshot is missing or cannot be decoded, or one property is asked of an object that does not exist
	 *
	 * @return array{object: ObjectData, files: list<array{property:string, path:string, file:string, outcome:'restored'|'missing'}>}
	 */
	public function restoreWithFiles(string $collection, string $id, string $file, ?string $property = null, bool $keepPasswords = false): array
	{
		$data = $this->read($collection, $id, $file);
		$live = $this->objects->existsObject($collection, $id) ? $this->objects->fetchObject($collection, $id) : null;

		if ($property !== null) {
			if (!$live instanceof ObjectData) {
				throw new \UnexpectedValueException("{$collection}/{$id} does not exist, so it can only be restored whole");
			}
			$data = [$property => $data[$property] ?? null] + $live->toArray();
		} elseif ($keepPasswords && $live instanceof ObjectData) {
			foreach ($live->properties as $key => $value) {
				if ($value instanceof PasswordData) {
					$data[(string)$key] = $value->transform();
				}
			}
		}

		// Record first, files second. The save can be refused, and a restore
		// that changed nothing must not have moved any file.
		$object = $this->updater->updateObject($collection, $id, $data);

		$files = [];
		foreach ($this->fileReferences($object) as [$referenced, $path, $name, $size]) {
			if ($property !== null && $referenced !== $property) {
				continue;
			}
			$outcome = $this->files->reconcile($collection, $id, $referenced, $path, $name, $size);
			if ($outcome === 'restored' || $outcome === 'missing') {
				$files[] = ['property' => $referenced, 'path' => $path, 'file' => $name, 'outcome' => $outcome];
			}
		}

		return ['object' => $object, 'files' => $files];
	}

	/**
	 * A snapshot as the record it holds, whatever format it was kept in.
	 *
	 * @param string $file a bare snapshot filename from BackupStore::listObjectSnapshots()
	 *
	 * @throws \UnexpectedValueException when the snapshot is missing or cannot be decoded
	 *
	 * @return array<string,mixed>
	 */
	public function read(string $collection, string $id, string $file): array
	{
		$contents = $this->store->readObjectSnapshot($collection, $id, $file);
		if ($contents === null) {
			throw new \UnexpectedValueException("Snapshot '{$file}' not found for {$collection}/{$id}");
		}

		$data = $this->codec->decode($contents, $this->formatOf($file), $this->bodyPropertyOf($collection));

		// A snapshot is authoritative about which record it belongs to.
		$data['id'] = $id;

		return $data;
	}

	/**
	 * Every file the record names: the property, the folder under it the file
	 * sits in (`''` for the property's own), the name, and the size when the
	 * record gives one.
	 *
	 * This only reads the record. Whether a place really holds files, and of
	 * what kind, is for the file history to say from the schema; a candidate it
	 * does not recognize is ignored there.
	 *
	 * @return list<array{0:string, 1:string, 2:string, 3:int|null}>
	 */
	private function fileReferences(ObjectData $object): array
	{
		$references = [];
		foreach ($object->properties as $property => $value) {
			$property = (string)$property;

			if ($value instanceof ImageData || $value instanceof FileData) {
				$references[] = [$property, '', $value->name, $value->size > 0 ? $value->size : null];
			} elseif ($value instanceof GalleryData) {
				$this->listedReferences($property, '', $value->images, $references);
			} elseif ($value instanceof DepotData) {
				$this->listedReferences($property, '', $value->files, $references);
			} elseif ($value instanceof CardData) {
				$this->nestedReferences($property, '', $value->card, $references);
			} elseif ($value instanceof DeckData) {
				foreach ($value->deck as $itemId => $item) {
					$this->nestedReferences($property, (string)$itemId, $item, $references);
				}
			} elseif ($value instanceof VideoData) {
				// A video's own poster.
				$this->nestedReferences($property, '', $value->transform(), $references);
			}
		}

		return $references;
	}

	/**
	 * The pictures of a gallery, or the files of a depot with its folders.
	 *
	 * @param array<ImageData|FileData|FolderData>                  $entries
	 * @param list<array{0:string, 1:string, 2:string, 3:int|null}> $references
	 */
	private function listedReferences(string $property, string $folder, array $entries, array &$references): void
	{
		foreach ($entries as $entry) {
			if ($entry instanceof FolderData) {
				$this->listedReferences($property, ltrim($folder . '/' . $entry->name, '/'), $entry->files, $references);
			} elseif ($entry->name !== '') {
				$references[] = [$property, $folder, $entry->name, $entry->size > 0 ? $entry->size : null];
			}
		}
	}

	/**
	 * The files inside a card or a deck item, which are stored as plain arrays.
	 * A child that looks like a file record (it has a `name` and a `mime`) is
	 * one; anything else is looked into, so a video's poster is found too.
	 *
	 * @param array<mixed>                                          $data
	 * @param list<array{0:string, 1:string, 2:string, 3:int|null}> $references
	 */
	private function nestedReferences(string $property, string $prefix, array $data, array &$references, int $depth = 0): void
	{
		foreach ($data as $key => $child) {
			if (!is_array($child)) {
				continue;
			}

			$path = ltrim($prefix . '/' . $key, '/');
			if (is_string($child['name'] ?? null) && array_key_exists('mime', $child)) {
				$size         = is_numeric($child['size'] ?? null) ? (int)$child['size'] : 0;
				$references[] = [$property, $path, $child['name'], $size > 0 ? $size : null];

				continue;
			}
			if ($depth < 3) {
				$this->nestedReferences($property, $path, $child, $references, $depth + 1);
			}
		}
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
