<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Backup\Service\FileBackupStore;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

class FileRemover
{
	public function __construct(
		protected PropertyRepository $storage,
		protected PropertyFetcher $propFetcher,
		protected ObjectPatcher $objectPatcher,
		protected ObjectFetcher $objectFetcher,
	) {
	}

	/** Where a removed file goes instead of being lost; null leaves removing as a plain delete. */
	protected ?FileBackupStore $fileBackups = null;

	/** Set by RemoverFactory: every remover is built with the same constructor. */
	public function setFileBackups(FileBackupStore $fileBackups): void
	{
		$this->fileBackups = $fileBackups;
	}

	protected function fetchProperty(string $collection, string $objectID, string $property): PropertyData
	{
		// Get the existing object property data
		$fileProperty = $this->propFetcher->fetchProperty($collection, $objectID, $property);

		return $fileProperty;
	}

	/** @param array<array<string,mixed>> $data */
	protected function updateObject(string $collection, string $objectID, string $property, array $data): ObjectData
	{
		$propertyData = [$property => $data];

		return $this->objectPatcher->patchObject($collection, $objectID, $propertyData);
	}

	public function deleteFile(
		string $collection,
		string $objectID,
		string $property,
		string $name,
		?string $subpath = null,
	): ObjectData {
		if (!$this->objectFetcher->existsObject($collection, $objectID)) {
			throw new \UnexpectedValueException("Object $objectID does not exist in $collection");
		}

		$propertyData = $this->fetchProperty($collection, $objectID, $property);

		// A card or deck never reaches this remover on purpose — its children
		// are addressed as nested paths and cleared one at a time. Should one
		// arrive here anyway, refusing beats the alternative: the single-value
		// branch below would write the whole deck back as `[]`.
		if ($propertyData instanceof CardData || $propertyData instanceof DeckData) {
			throw new \UnexpectedValueException("Property '{$property}' is a " . ($propertyData instanceof DeckData ? 'deck' : 'card') . "; address its child as {$property}/{child}");
		}

		$files = $propertyData->transform();

		// This is the fallback remover for every property type without one of
		// its own, which is not just the gallery shape it was written for.
		// GalleryData::transform() returns a LIST of file maps; ImageData and
		// FileData return a flat map of a single file's own fields, so the
		// filter below was handed `'upload-test.png'['name']` and threw
		// "Cannot access offset of type string on string" — a 500 on every
		// delete from a single-image or single-file property.
		//
		// There is no list to filter in the single-value case: the file being
		// deleted IS the property, so removing it empties it.
		//
		// Record first, disk second, in both branches. The save can be refused
		// for a reason unrelated to this file (whole-object validation), and a
		// file deleted ahead of a refused save is gone for good while the
		// record still names it. A refused save now changes nothing.
		if (!array_is_list($files)) {
			$updated = $this->updateObject($collection, $objectID, $property, []);

			// The record is saved, so the file is now going for certain: the file
			// history keeps it first, when the settings say so.
			$this->fileBackups?->setAside($collection, $objectID, $property);
			$this->storage->deleteFile($collection, $objectID, $property, $name);

			return $updated;
		}

		foreach ($files as $key => $file) {
			if (is_array($file) && ($file['name'] ?? null) === $name) {
				unset($files[$key]);
				break;
			}
		}

		// Reindex the array
		$files = array_values($files);

		$updated = $this->updateObject($collection, $objectID, $property, $files);

		// One picture out of a gallery: it goes to the trash when the trash is on.
		$this->fileBackups?->setAside($collection, $objectID, $property, null, $name);
		$this->storage->deleteFile($collection, $objectID, $property, $name);

		return $updated;
	}

	/**
	 * Remove several images from a gallery with one save.
	 *
	 * The gallery field's multi-delete calls this rather than deleteFile() once
	 * per image: each deleteFile() is a whole object save, so N of them fired
	 * N `object.updated` events (N automation runs, N webhook calls, N search
	 * index writes), and N requests sent at once raced each other's
	 * read-modify-write of the record and could put a deleted image back.
	 *
	 * Only names the record holds are removed, and the disk delete uses the
	 * record's own name, so a name in the request can never reach a file the
	 * gallery does not list. Names it does not hold (already deleted in
	 * another tab, say) are reported, not an error.
	 *
	 * Record first, disk second, as in deleteFile(): a refused save changes
	 * nothing. Once the record is saved the files are going for certain; a
	 * file that cannot be removed does not stop the others, and the first
	 * such failure is thrown after every file has been tried.
	 *
	 * @param list<string> $names
	 *
	 * @return array{deleted: list<string>, missing: list<string>}
	 */
	public function deleteFiles(string $collection, string $objectID, string $property, array $names): array
	{
		if (!$this->objectFetcher->existsObject($collection, $objectID)) {
			throw new \UnexpectedValueException("Object $objectID does not exist in $collection");
		}

		$gallery = $this->fetchProperty($collection, $objectID, $property);
		if (!$gallery instanceof GalleryData) {
			throw new \InvalidArgumentException("Property '{$property}' is not a gallery; delete its files one at a time");
		}

		$wanted  = array_fill_keys($names, true);
		$kept    = [];
		$deleted = [];
		foreach ($gallery->transform() as $image) {
			$name = is_string($image['name'] ?? null) ? $image['name'] : '';
			if ($name !== '' && isset($wanted[$name]) && self::isPlainName($name)) {
				$deleted[] = $name;
				unset($wanted[$name]);
				continue;
			}
			$kept[] = $image;
		}
		$missing = array_map(strval(...), array_keys($wanted));

		if ($deleted === []) {
			return ['deleted' => [], 'missing' => $missing];
		}

		$this->updateObject($collection, $objectID, $property, $kept);

		$failure = null;
		foreach ($deleted as $name) {
			try {
				$this->fileBackups?->setAside($collection, $objectID, $property, null, $name);
				$this->storage->deleteFile($collection, $objectID, $property, $name);
			} catch (\RuntimeException $e) {
				$failure ??= $e;
			}
		}
		if ($failure instanceof \RuntimeException) {
			throw $failure;
		}

		return ['deleted' => $deleted, 'missing' => $missing];
	}

	/** A file name of the property's own folder: no path, and not `.` or `..`. */
	private static function isPlainName(string $name): bool
	{
		return $name === basename($name) && !in_array($name, ['.', '..'], true) && !str_contains($name, '\\');
	}
}
