<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;

/**
 * A file that lives in an object property, ready to be served: a plain
 * `file` property, an entry in a `depot`, or a file nested in a card or deck
 * item. The download and stream actions ask it to exist, load its access
 * rules, fetch its record, size and open it, and record the download — and
 * never need to know which of the three it is.
 *
 * Built by {@see PropertyFileResolver}.
 */
final readonly class PropertyFile
{
	/**
	 * @param string      $name          The filename as the URL named it (depot) or as the nested record carries it.
	 * @param string|null $subpath       Depot subfolder (the `?path=` query), depot entries only.
	 * @param string|null $nestedSubpath The card/deck path under the property, nested files only.
	 * @param bool        $missing       A nested path with no file record behind it (a depot subfolder that looked nested).
	 */
	public function __construct(
		private FileFetcher $fileFetcher,
		private DepotFileFetcher $depotFetcher,
		private PropertyFetcher $propertyFetcher,
		private ObjectUpdater $objectUpdater,
		private PropertyFileKind $kind,
		public string $collection,
		public string $id,
		public string $property,
		public string $name = '',
		public ?string $subpath = null,
		private ?string $nestedSubpath = null,
		private bool $missing = false,
	) {
	}

	public function exists(): bool
	{
		if ($this->missing) {
			return false;
		}

		return match ($this->kind) {
			PropertyFileKind::File   => $this->fileFetcher->fileExists($this->collection, $this->id, $this->property),
			PropertyFileKind::Nested => $this->fileFetcher->fileExists($this->collection, $this->id, $this->property, $this->nestedSubpath),
			PropertyFileKind::Depot  => $this->depotFetcher->fileExists($this->collection, $this->id, $this->property, $this->name, $this->subpath),
		};
	}

	/**
	 * Load this file's protection (groups, password) into the access manager.
	 * A nested file carries its own per-leaf protection.
	 */
	public function load(FileAccessManager $accessManager): void
	{
		match ($this->kind) {
			PropertyFileKind::File   => $accessManager->loadFile($this->collection, $this->id, $this->property),
			PropertyFileKind::Nested => $accessManager->loadFile($this->collection, $this->id, $this->property, $this->nestedSubpath),
			PropertyFileKind::Depot  => $accessManager->loadDepotFile($this->collection, $this->id, $this->property),
		};
	}

	public function fetch(): FileData
	{
		return match ($this->kind) {
			PropertyFileKind::File   => $this->fileFetcher->fetchFile($this->collection, $this->id, $this->property),
			PropertyFileKind::Nested => $this->fileFetcher->fetchFile($this->collection, $this->id, $this->property, $this->nestedSubpath),
			PropertyFileKind::Depot  => $this->depotFetcher->fetchFile($this->collection, $this->id, $this->property, $this->name, $this->subpath),
		};
	}

	public function size(): int
	{
		return match ($this->kind) {
			PropertyFileKind::File   => $this->fileFetcher->fileSize($this->collection, $this->id, $this->property),
			PropertyFileKind::Nested => $this->fileFetcher->fileSize($this->collection, $this->id, $this->property, $this->nestedSubpath),
			PropertyFileKind::Depot  => $this->depotFetcher->fileSize($this->collection, $this->id, $this->property, $this->name, $this->subpath),
		};
	}

	/** @return resource */
	public function open(): mixed
	{
		return match ($this->kind) {
			PropertyFileKind::File   => $this->fileFetcher->streamFile($this->collection, $this->id, $this->property),
			PropertyFileKind::Nested => $this->fileFetcher->streamFile($this->collection, $this->id, $this->property, $this->nestedSubpath),
			PropertyFileKind::Depot  => $this->depotFetcher->streamFile($this->collection, $this->id, $this->property, $this->name, $this->subpath),
		};
	}

	/**
	 * Bump the download counter on the record, without touching siblings in
	 * a depot or a parent card/deck.
	 */
	public function recordDownload(FileData $file): void
	{
		switch ($this->kind) {
			case PropertyFileKind::File:
				$file->count++;
				$this->objectUpdater->updateObjectProperty($this->collection, $this->id, $this->property, $file->transform(), silent: true);

				return;

			case PropertyFileKind::Nested:
				$file->count++;
				$this->objectUpdater->updateNestedProperty($this->collection, $this->id, $this->property, (string)$this->nestedSubpath, $file->transform());

				return;

			case PropertyFileKind::Depot:
				$depot = $this->propertyFetcher->fetchProperty($this->collection, $this->id, $this->property);
				if (!$depot instanceof DepotData) {
					throw new \RuntimeException('Expected instance of DepotData');
				}

				(new DepotPropertyManager($depot))->patchMeta($this->name, ['count' => $file->count + 1], $this->subpath);
				$this->objectUpdater->updateObjectProperty($this->collection, $this->id, $this->property, $depot->transform(), silent: true);

				return;
		}
	}
}
