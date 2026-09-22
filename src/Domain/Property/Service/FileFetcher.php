<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

readonly class FileFetcher
{
	public function __construct(
		private PropertyRepository $storage,
		private PropertyFetcher $propFetcher,
		private ObjectFetcher $objectFetcher,
	) {
	}

	/**
	 * Fetch file metadata. When $subpath is set the file lives under a parent
	 * card or deck-item: walk the parent's raw object data to find the leaf
	 * FileData (single segment for card child, `itemId/child` for deck child).
	 */
	public function fetchFile(string $collection, string $id, string $property, ?string $subpath = null): FileData
	{
		if ($subpath === null || $subpath === '') {
			$file = $this->propFetcher->fetchProperty($collection, $id, $property);

			if (!$file instanceof FileData) {
				throw new \RuntimeException('Unable to retrieve file data');
			}

			return $file;
		}

		return $this->fetchNestedFile($collection, $id, $property, $subpath);
	}

	public function fileExists(string $collection, string $id, string $property, ?string $subpath = null): bool
	{
		$file = $this->fetchFile($collection, $id, $property, $subpath);

		return $this->storage->fileExists($collection, $id, $property, $file->name, $subpath);
	}

	/**
	 * True when `$subpath` resolves to an on-disk directory under the property —
	 * the dispatch signal that distinguishes a card/deck-nested leaf from a
	 * legacy flat gallery/depot filename at the same URL shape. An empty or null
	 * subpath is not nested by definition.
	 */
	/**
	 * Whether `{property}/{subpath}` addresses a child nested inside a card or
	 * deck item, as opposed to a file inside a gallery or depot.
	 *
	 * The stored property's type decides: a card or deck is nested whatever
	 * the disk says. The directory alone used to decide, and a child that had
	 * never been uploaded — or whose directory another tab had just deleted —
	 * fell through to the flat-file path, which treats the whole deck as one
	 * single-value property and writes it back as `[]`. The directory check
	 * stays as the answer for a property the record does not hold yet (a
	 * stale upload directory, an id whose case differs on disk).
	 */
	public function isNestedDirectory(string $collection, string $id, string $property, ?string $subpath): bool
	{
		if ($subpath === null || $subpath === '') {
			return false;
		}

		if ($this->isComposite($collection, $id, $property)) {
			return true;
		}

		return $this->storage->directoryExists($collection, $id, $property, $subpath);
	}

	/**
	 * Whether the stored property is a card or a deck — the two shapes whose
	 * children live at nested paths. False when the object or property cannot
	 * be read; the caller falls back to the directory check.
	 */
	private function isComposite(string $collection, string $id, string $property): bool
	{
		try {
			$data = $this->propFetcher->fetchProperty($collection, $id, $property);
		} catch (\Throwable) {
			return false;
		}

		return $data instanceof CardData || $data instanceof DeckData;
	}

	public function fileSize(string $collection, string $id, string $property, ?string $subpath = null): int
	{
		$file = $this->fetchFile($collection, $id, $property, $subpath);

		return $this->storage->fileSize($collection, $id, $property, $file->name, $subpath);
	}

	/** @return resource */
	public function streamFile(string $collection, string $id, string $property, ?string $subpath = null)
	{
		$file = $this->fetchFile($collection, $id, $property, $subpath);

		return $this->storage->streamFile($collection, $id, $property, $file->name, $subpath);
	}

	/**
	 * Walk the object's raw data from `$property` through the slash-separated
	 * `$subpath` segments to the leaf FileData. Mirrors the write-side traversal
	 * in ObjectPatcher::patchNestedProperty so reads and writes stay in sync.
	 */
	private function fetchNestedFile(string $collection, string $id, string $property, string $subpath): FileData
	{
		$objectData = $this->objectFetcher->fetchObject($collection, $id)->toArray();

		$cursor = $objectData[$property] ?? null;
		foreach (explode('/', $subpath) as $segment) {
			if (!is_array($cursor)) {
				throw new \RuntimeException("Unable to locate nested file at {$property}/{$subpath}");
			}
			$cursor = $cursor[$segment] ?? null;
		}

		if (!is_array($cursor)) {
			throw new \RuntimeException("Nested file data missing at {$property}/{$subpath}");
		}

		return new FileData($cursor);
	}
}
