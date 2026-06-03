<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Repair\Service;

use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Repair\Data\RepairCandidate;
use TotalCMS\Domain\Repair\Data\RepairFilters;
use TotalCMS\Domain\Repair\Data\RepairReport;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Finds file/image/gallery/depot properties that are blank in an object's JSON
 * but still have files on disk, and rebuilds their metadata from those files
 * (the recovery path for a PUT that omitted the field). Top-level properties
 * only; card/deck-nested fields are out of scope.
 */
final readonly class CollectionFileRepairService
{
	/** @var list<string> */
	private const FILE_FIELDS = ['file', 'image', 'gallery', 'depot'];

	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private IndexReader $indexReader,
		private ObjectFetcher $objectFetcher,
		private ObjectPatcher $objectPatcher,
		private SaverFactory $saverFactory,
	) {
	}

	public function scan(string $collection, RepairFilters $filters): RepairReport
	{
		return $this->run($collection, $filters, false);
	}

	public function apply(string $collection, RepairFilters $filters): RepairReport
	{
		return $this->run($collection, $filters, true);
	}

	private function run(string $collection, RepairFilters $filters, bool $apply): RepairReport
	{
		$report  = new RepairReport($collection, $apply);
		$targets = $this->targetProperties($collection, $filters);
		if ($targets === []) {
			return $report;
		}

		foreach ($this->indexReader->fetchIndex($collection)->objects as $row) {
			$id = (string)($row['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$report->scanned++;
			$object = $this->objectFetcher->fetchObjectFromDisk($collection, $id);

			foreach ($targets as $property => $type) {
				$this->repairProperty($report, $object, $collection, $id, $property, $type, $apply);
			}
		}

		return $report;
	}

	private function repairProperty(RepairReport $report, ObjectData $object, string $collection, string $id, string $property, string $type, bool $apply): void
	{
		$current = $object->properties->get($property);
		if (!$this->isBlank($current)) {
			return; // has data — never overwrite
		}

		try {
			$saver   = $this->saverFactory->generateSaverService($collection, $property, $id);
			$rebuilt = $saver->rebuildFromStorage($collection, $id, $property);
		} catch (\Throwable $e) {
			$candidate         = new RepairCandidate($id, $property, $type, 0);
			$candidate->error  = $e->getMessage();
			$report->addCandidate($candidate);

			return;
		}

		if ($rebuilt === null) {
			$report->blankWithoutFiles++; // blank, but nothing on disk to rebuild from

			return;
		}

		$candidate = new RepairCandidate($id, $property, $type, $this->fileCount($rebuilt));

		if ($apply) {
			try {
				$this->objectPatcher->patchObject($collection, $id, [$property => $rebuilt->transform()], true);
				$candidate->applied = true;
			} catch (\Throwable $e) {
				$candidate->applied = false;
				$candidate->error   = $e->getMessage();
			}
		}

		$report->addCandidate($candidate);
	}

	/**
	 * Top-level file-type properties from the collection's schema, honoring filters.
	 *
	 * @return array<string,string> property name => field type
	 */
	private function targetProperties(string $collection, RepairFilters $filters): array
	{
		try {
			$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);
		} catch (\Throwable) {
			return [];
		}

		$targets = [];
		foreach ($schema->properties as $name => $definition) {
			if (!is_array($definition)) {
				continue;
			}
			$field = (string)($definition['field'] ?? '');
			if (!in_array($field, self::FILE_FIELDS, true)) {
				continue;
			}
			if (!$filters->allowsType($field) || !$filters->allowsProperty((string)$name)) {
				continue;
			}
			$targets[(string)$name] = $field;
		}

		return $targets;
	}

	private function isBlank(?PropertyData $property): bool
	{
		return match (true) {
			$property === null              => true,
			$property instanceof ImageData  => $property->name === '',
			$property instanceof FileData   => $property->name === '',
			$property instanceof GalleryData => $property->images === [],
			$property instanceof DepotData  => $property->files === [],
			default                         => false,
		};
	}

	private function fileCount(PropertyData $rebuilt): int
	{
		return match (true) {
			$rebuilt instanceof GalleryData => count($rebuilt->images),
			$rebuilt instanceof DepotData   => $this->countFilesInTree((array)($rebuilt->transform()['files'] ?? [])),
			default                         => 1,
		};
	}

	/** @param array<int,mixed> $entries */
	private function countFilesInTree(array $entries): int
	{
		$count = 0;
		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			if (($entry['mime'] ?? '') === 'folder') {
				$count += $this->countFilesInTree((array)($entry['files'] ?? []));
			} else {
				$count++;
			}
		}

		return $count;
	}
}
