<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
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
