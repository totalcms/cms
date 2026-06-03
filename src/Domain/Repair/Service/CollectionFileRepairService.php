<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Repair\Service;

use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Repair\Data\RepairCandidate;
use TotalCMS\Domain\Repair\Data\RepairFilters;
use TotalCMS\Domain\Repair\Data\RepairReport;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Finds file/image/gallery/depot properties that are blank in an object's JSON
 * but still have files on disk, and rebuilds their metadata from those files
 * (the recovery path for a PUT that omitted the field). Handles top-level
 * properties and file/image fields nested one level inside a card or deck.
 */
final readonly class CollectionFileRepairService
{
	/** Top-level file-type field types. @var list<string> */
	private const FILE_FIELDS = ['file', 'image', 'gallery', 'depot'];

	/** Field types that nest other fields. @var list<string> */
	private const CONTAINER_FIELDS = ['card', 'deck'];

	/**
	 * File-type fields supported *inside* a card/deck. Cards and decks do not
	 * support gallery or depot children, so the nested scope is narrower than
	 * the top-level one.
	 *
	 * @var list<string>
	 */
	private const NESTED_FILE_FIELDS = ['file', 'image'];

	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private IndexReader $indexReader,
		private ObjectFetcher $objectFetcher,
		private ObjectPatcher $objectPatcher,
		private SaverFactory $saverFactory,
		private PropertyRepository $storage,
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
		$report        = new RepairReport($collection, $apply);
		$targets       = $this->targetProperties($collection, $filters);
		$nestedTargets = $this->nestedTargets($collection, $filters);
		if ($targets === [] && $nestedTargets === []) {
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

			foreach ($nestedTargets as $property => $spec) {
				$this->repairNested($report, $object, $collection, $id, $property, $spec, $apply);
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

		if (!$rebuilt instanceof PropertyData) {
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
	 * Repair the file/image children of one card or deck property.
	 *
	 * Card children are addressed by the child key (`subpath = "image"`); deck
	 * children by item id + child key (`subpath = "one/image"`). Deck item ids
	 * are read from disk rather than the JSON, so a deck that was wholly blanked
	 * out of the object can still be recovered from the files left behind.
	 *
	 * @param array{kind:string,children:array<string,string>} $spec
	 */
	private function repairNested(RepairReport $report, ObjectData $object, string $collection, string $id, string $property, array $spec, bool $apply): void
	{
		$parent   = $object->properties->get($property);
		$children = $spec['children'];

		if ($spec['kind'] === 'card') {
			foreach ($children as $childKey => $type) {
				$existing = $parent instanceof CardData ? $parent->get($childKey) : null;
				$this->repairNestedChild($report, $collection, $id, $property, $childKey, $type, $existing, $apply);
			}

			return;
		}

		foreach ($this->storage->listPropertyDirectories($collection, $id, $property) as $itemId) {
			$item      = $parent instanceof DeckData ? $parent->getItem($itemId) : null;
			$itemHasId = is_array($item) && ($item['id'] ?? '') !== '';
			foreach ($children as $childKey => $type) {
				$existing = is_array($item) ? ($item[$childKey] ?? null) : null;
				$this->repairNestedChild($report, $collection, $id, $property, $itemId . '/' . $childKey, $type, $existing, $apply, $itemId, $itemHasId);
			}
		}
	}

	/**
	 * Rebuild and (on apply) write one nested file/image child.
	 *
	 * `$subpath` locates the files on disk and the child to rebuild. For a card
	 * child the write targets that child leaf directly. For a deck child
	 * (`$itemId` set) the write targets the *item* so a missing `id` — required
	 * by the deck schema and absent when the item was rebuilt purely from disk —
	 * can be backfilled alongside the child data.
	 */
	private function repairNestedChild(RepairReport $report, string $collection, string $id, string $property, string $subpath, string $type, mixed $existing, bool $apply, ?string $itemId = null, bool $itemHasId = true): void
	{
		if (!$this->isBlankRaw($existing)) {
			return; // child already has data — never overwrite
		}

		try {
			$saver   = $this->saverFactory->generateSaverService($collection, $property, $id, $subpath);
			$rebuilt = $saver->rebuildFromStorage($collection, $id, $property, $subpath);
		} catch (\Throwable $e) {
			$candidate          = new RepairCandidate($id, $property, $type, 0);
			$candidate->subpath = $subpath;
			$candidate->error   = $e->getMessage();
			$report->addCandidate($candidate);

			return;
		}

		if (!$rebuilt instanceof PropertyData) {
			$report->blankWithoutFiles++; // blank, but nothing on disk to rebuild from

			return;
		}

		$candidate          = new RepairCandidate($id, $property, $type, $this->fileCount($rebuilt));
		$candidate->subpath = $subpath;

		if ($apply) {
			try {
				[$path, $data] = $this->nestedPatchTarget($subpath, $rebuilt, $itemId, $itemHasId);
				$this->objectPatcher->patchNestedProperty($collection, $id, $property, $path, $data, true);
				$candidate->applied = true;
			} catch (\Throwable $e) {
				$candidate->applied = false;
				$candidate->error   = $e->getMessage();
			}
		}

		$report->addCandidate($candidate);
	}

	/**
	 * Resolve where a rebuilt nested child is written and with what payload.
	 *
	 * Card child → patch the child leaf with its own data. Deck child → patch the
	 * item with `{childKey: data}` plus the backfilled `id` when the item lacked
	 * one (so the deck schema's required `id` is satisfied for disk-only items).
	 *
	 * @return array{0:string,1:array<string,mixed>}
	 */
	private function nestedPatchTarget(string $subpath, PropertyData $rebuilt, ?string $itemId, bool $itemHasId): array
	{
		if ($itemId === null) {
			return [$subpath, $rebuilt->transform()];
		}

		$childKey = substr($subpath, strrpos($subpath, '/') + 1);
		$data     = [$childKey => $rebuilt->transform()];
		if (!$itemHasId) {
			$data['id'] = $itemId;
		}

		return [$itemId, $data];
	}

	/**
	 * Card/deck properties whose schema has at least one file/image child,
	 * honoring filters.
	 *
	 * @return array<string,array{kind:string,children:array<string,string>}>
	 */
	private function nestedTargets(string $collection, RepairFilters $filters): array
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
			if (!in_array($field, self::CONTAINER_FIELDS, true) || !$filters->allowsProperty((string)$name)) {
				continue;
			}

			$children = $this->nestedFileChildren($definition, $filters);
			if ($children === []) {
				continue;
			}
			$targets[(string)$name] = ['kind' => $field, 'children' => $children];
		}

		return $targets;
	}

	/**
	 * File/image child keys of a card/deck property, resolved from its schemaref.
	 *
	 * @param array<string,mixed> $definition
	 *
	 * @return array<string,string> child key => field type (file|image)
	 */
	private function nestedFileChildren(array $definition, RepairFilters $filters): array
	{
		$ref = PropertyDefinition::extractSchemaRef($definition);
		if ($ref === null) {
			return [];
		}

		try {
			$childSchema = $this->schemaFetcher->fetchSchema(SchemaFetcher::extractSchemaId($ref));
		} catch (\Throwable) {
			return [];
		}

		$children = [];
		foreach ($childSchema->properties as $childKey => $childDef) {
			if (!is_array($childDef)) {
				continue;
			}
			$childField = (string)($childDef['field'] ?? '');
			if (!in_array($childField, self::NESTED_FILE_FIELDS, true) || !$filters->allowsType($childField)) {
				continue;
			}
			$children[(string)$childKey] = $childField;
		}

		return $children;
	}

	/**
	 * Blank test for a raw nested child array (file/image only — both keyed by
	 * `name`). A missing/scalar value or empty name means nothing was persisted.
	 */
	private function isBlankRaw(mixed $raw): bool
	{
		if (!is_array($raw)) {
			return true;
		}

		return ($raw['name'] ?? '') === '';
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
			!$property instanceof PropertyData              => true,
			$property instanceof ImageData                  => $property->name === '',
			$property instanceof FileData                   => $property->name === '',
			$property instanceof GalleryData                => $property->images === [],
			$property instanceof DepotData                  => $property->files === [],
			default                                         => false,
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
