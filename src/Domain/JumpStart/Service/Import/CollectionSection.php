<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service\Import;

use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Builder\Service\BuilderOrderService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\JumpStart\Data\ImportReport;

/**
 * The `collections` section of a JumpStart definition: custom collections,
 * reserved collections (a bare id, or an id with overrides), and the page
 * orders that travel with their settings but can only be applied once the
 * pages exist — see {@see applyPendingPageOrder()}.
 */
class CollectionSection
{
	/**
	 * Page orders lifted off incoming collection settings, keyed by
	 * collection id, awaiting the objects they arrange.
	 *
	 * @var array<string,list<array<string,mixed>>>
	 */
	private array $pendingPageOrder = [];

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CollectionSaver $collectionSaver,
		private readonly BackupStore $syncBackup,
		private readonly BuilderOrderService $orderService,
	) {
	}

	/** @param array<string,mixed> $collections */
	public function import(array $collections, ImportRun $run): void
	{
		$collections = $this->stashPageOrder($collections);

		if (isset($collections['custom'])) {
			foreach ($collections['custom'] as $collectionDef) {
				if ($run->refuseSystemCollection((string)($collectionDef['id'] ?? ''), 'Collection')) {
					continue;
				}
				try {
					$this->createCustomCollection($collectionDef, $run);
				} catch (\Exception $e) {
					$run->report->error(sprintf('Collection %s: %s', $collectionDef['id'] ?? 'unknown', $e->getMessage()));
				}
			}
		}

		// Reserved entries are either a string id ("blog") — create with
		// defaults — or an object with id + overrides — create, then patch.
		// The object form lets starters set `url`, `prettyUrl`, `sortBy`,
		// `name` etc. on a reserved collection without losing the built-in
		// schema binding.
		if (isset($collections['reserved'])) {
			foreach ($collections['reserved'] as $entry) {
				$id = is_string($entry) ? $entry : (string)($entry['id'] ?? 'unknown');
				if ($run->refuseSystemCollection($id, 'Collection')) {
					continue;
				}
				try {
					$this->createReservedCollection($entry, $run);
				} catch (\Exception $e) {
					$run->report->error(sprintf('Collection %s: %s', $id, $e->getMessage()));
				}
			}
		}
	}

	/**
	 * Apply the page orders held back by stashPageOrder(), once the pages
	 * they describe have been imported. Writes through BuilderOrderService
	 * so the incoming tree is reconciled against this site's pages: ids the
	 * destination lacks are dropped, pages the tree omits are appended, so a
	 * partial or stale tree cannot orphan a page.
	 */
	public function applyPendingPageOrder(ImportRun $run): void
	{
		foreach ($this->pendingPageOrder as $collectionId => $tree) {
			if ($run->refuseSystemCollection($collectionId, 'Page order')) {
				continue;
			}

			try {
				$this->orderService->write($collectionId, $tree);
				$run->report->result(null, sprintf('Page order %s: applied', $collectionId));
			} catch (\Throwable $e) {
				$run->report->error(sprintf('Page order %s: %s', $collectionId, $e->getMessage()));
			}
		}

		$this->pendingPageOrder = [];
	}

	/**
	 * Lift `pageOrder` off each collection entry and hold it until the pages
	 * exist. The order travels inside the collection's settings — it is
	 * configuration, so it belongs to the same selection the operator makes
	 * for settings — but collections are processed before objects, and
	 * BuilderOrderService::write() reconciles the tree against the pages that
	 * currently exist, so an order written at collection time would have
	 * every id stripped as unknown. Stripping the key also keeps it out of
	 * CollectionSaver, which would otherwise be handed a field that is not
	 * part of CollectionData.
	 *
	 * @param array<string,mixed> $collections
	 *
	 * @return array<string,mixed>
	 */
	private function stashPageOrder(array $collections): array
	{
		foreach (['custom', 'reserved'] as $kind) {
			if (!isset($collections[$kind]) || !is_array($collections[$kind])) {
				continue;
			}

			foreach ($collections[$kind] as $index => $entry) {
				// Bare-string reserved entries carry no settings at all.
				if (!is_array($entry) || !isset($entry['pageOrder'])) {
					continue;
				}

				$id = (string)($entry['id'] ?? '');
				if ($id !== '' && is_array($entry['pageOrder'])) {
					$this->pendingPageOrder[$id] = BuilderOrderService::normalizeTree($entry['pageOrder']);
				}

				unset($entry['pageOrder']);
				$collections[$kind][$index] = $entry;
			}
		}

		return $collections;
	}

	/** @param string|array<string,mixed> $entry */
	private function createReservedCollection(string|array $entry, ImportRun $run): void
	{
		$id = is_string($entry) ? $entry : (string)($entry['id'] ?? '');
		if ($id === '') {
			throw new \Exception('Reserved collection entry missing id');
		}

		$existed    = $this->collectionFetcher->fetchCollection($id) instanceof CollectionData;
		$collection = $this->collectionFetcher->fetchOrCreateReserved($id);
		if (!$collection instanceof CollectionData) {
			throw new \Exception("Error creating Reserved Collection: {$id}");
		}

		if (is_array($entry)) {
			$overrides = $entry;
			unset($overrides['id']);
			if ($overrides !== [] && $run->upsert && $existed) {
				// Sync mode against an existing collection: the entry is the
				// source's full settings — mirror them (including clearing
				// keys the source emptied), never the local counters.
				$this->upsertCollectionMeta($id, $entry, $collection, $run);

				return;
			}
			if ($overrides !== []) {
				// Starter-kit semantics: shallow patch of the overrides on
				// top of defaults, without touching the schema binding.
				$this->collectionSaver->patchCollection($id, $overrides);
			}
		}

		$run->report->result(ImportReport::COLLECTIONS, sprintf('Collection %s: %s', $collection->id, $existed ? 'exists' : 'created'));
	}

	/** @param array<string,mixed> $collectionDef */
	private function createCustomCollection(array $collectionDef, ImportRun $run): void
	{
		$id       = (string)($collectionDef['id'] ?? '');
		$existing = $id !== '' ? $this->collectionFetcher->fetchCollection($id) : null;

		if ($run->upsert && $existing instanceof CollectionData) {
			$this->upsertCollectionMeta($id, $collectionDef, $existing, $run);

			return;
		}

		// preserveDates: an imported collection keeps its source's `updated`
		// (settings) timestamp — restamping would make the copy read newer
		// than the original (same rule as schemas and objects).
		$collection = $this->collectionSaver->saveCollection($this->stripComputedCollectionFields($collectionDef), preserveDates: true);
		$run->report->result(ImportReport::COLLECTIONS, sprintf('Collection %s: created', $collection->id));
	}

	/**
	 * Mirror synced collection settings onto an existing local collection.
	 *
	 * The incoming payload carries the source's full configuration (with
	 * explicit empties, so an emptied card clears here too); merging it over
	 * the local array keeps everything it doesn't carry — crucially the
	 * environment-local counters, which are also stripped from the incoming
	 * side outright so no payload can ever move them. `count` feeds oid
	 * generation, and lowering it would collide new object ids.
	 *
	 * `format` is stripped here too, but NOT in stripComputedCollectionFields():
	 * this method only touches a collection that already exists on disk in
	 * its own format, and CollectionSaver::updateCollection() would refuse a
	 * payload that disagreed with it (the setting and the files must agree;
	 * only CollectionFormatConverter changes both). The create path has no
	 * existing format to protect, so a `jumpstart:export` of a markdown
	 * collection can restore as markdown on a fresh install.
	 *
	 * @param array<string,mixed> $incoming
	 */
	private function upsertCollectionMeta(string $collectionId, array $incoming, CollectionData $existing, ImportRun $run): void
	{
		$this->syncBackup->backupCollectionMeta($collectionId);

		$data = array_merge($existing->toArray(), $this->stripComputedCollectionFields($incoming));
		unset($data['format']);
		$data['format'] = $existing->format;

		$this->collectionSaver->updateCollection($collectionId, $data, $existing, preserveDates: true);
		$run->report->result(ImportReport::COLLECTIONS, sprintf('Collection %s: updated', $collectionId));
	}

	/**
	 * The environment-local computed fields sync must never carry into a
	 * write: `count` (lifetime oid counter), `totalObjects`, `lastUpdated`.
	 * The exporter already strips them; stripping again here enforces the
	 * rule against any hand-built payload. `format` is deliberately kept —
	 * see upsertCollectionMeta().
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function stripComputedCollectionFields(array $data): array
	{
		unset($data['count'], $data['totalObjects'], $data['lastUpdated']);

		return $data;
	}
}
