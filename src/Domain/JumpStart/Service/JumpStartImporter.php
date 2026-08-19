<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Builder\Service\BuilderOrderService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ImportEventPayload;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Sync\Service\SyncBackupService;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\OperationResult;
use TotalCMS\Support\PathResolver;

/** @SuppressWarnings("PHPMD.ExcessiveClassComplexity") */
class JumpStartImporter
{
	private function demoJumpstartFile(): string
	{
		return PathResolver::packageRoot() . '/resources/jumpstart/demo.json';
	}

	private readonly LoggerInterface $logger;

	/** @var array<int, string> */
	private array $results = [];

	/** @var array<int, string> */
	private array $errors = [];

	/**
	 * Whether this import is allowed to write code-executing system collections
	 * (e.g. `automations`, whose handler is unsandboxed PHP — a direct RCE
	 * vector). Set per-call from importFromDefinition(). Defaults to refusing
	 * them: only callers that have established a super-admin operator (the
	 * admin-UI actions) or that run with shell trust (CLI commands, the local
	 * sync-apply) opt in. This mirrors SystemCollectionGuardMiddleware's policy
	 * for the generic `/api/collections` write path, so every write surface
	 * enforces the same "super-admin only" rule regardless of route.
	 */
	private bool $allowSystemCollections = false;

	/**
	 * Whether this import runs in sync/upsert mode. Mirrors the
	 * $allowSystemCollections pattern: set per-call from importFromDefinition().
	 * Upsert mode is what turns an import into a blind overwrite, so it is
	 * also what gates the pre-overwrite backups (SyncBackupService) — a
	 * starter-kit import never overwrites and needs no snapshots.
	 */
	private bool $upsert = false;

	/**
	 * Page orders lifted off incoming collection settings, keyed by collection
	 * id, awaiting the objects they arrange. See stashPageOrder().
	 *
	 * @var array<string,list<array<string,mixed>>>
	 */
	private array $pendingPageOrder = [];

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CollectionSaver $collectionSaver,
		private readonly ObjectFetcher $objectFetcher,
		private readonly ObjectSaver $objectSaver,
		private readonly ObjectUpdater $objectUpdater,
		private readonly SchemaSaver $schemaSaver,
		private readonly SchemaFetcher $schemaFetcher,
		private readonly TemplateSaver $templateSaver,
		private readonly FactoryImporter $factoryImporter,
		private readonly EventDispatcher $eventDispatcher,
		private readonly SyncBackupService $syncBackup,
		private readonly BuilderOrderService $orderService,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::JumpStartImporter);
	}

	/**
	 * Save an object as part of an import: suppress `object.created` for the
	 * collection (so user-facing listeners don't see import-time writes), then
	 * fire `import.created` so import-specific listeners get a per-object
	 * notification.
	 *
	 * @param array<string,mixed> $objectData
	 */
	private function saveImportedObject(string $collection, array $objectData): ObjectData
	{
		$this->eventDispatcher->suspendForImport($collection);
		try {
			// preserveDates: imported data is the authoritative history of its
			// source object — authored created/updated values travel verbatim,
			// empty ones still stamp normally.
			$object = $this->objectSaver->saveObject($collection, $objectData, preserveDates: true);
			$this->eventDispatcher->dispatch(
				CoreEvent::IMPORT_CREATED,
				new ObjectEventPayload($collection, $object->id, $object),
			);

			return $object;
		} finally {
			$this->eventDispatcher->resumeForImport($collection);
		}
	}

	/**
	 * Mirror of saveImportedObject() for the upsert path (sync push). Calls
	 * ObjectUpdater so the existing storage row is replaced rather than
	 * conflicted-out, while keeping the same event semantics: the inner
	 * `object.updated` is suppressed by the import suspension, and we fire
	 * `import.updated` so import-specific listeners get a per-object hook
	 * distinct from the user-driven update path.
	 *
	 * @param array<string,mixed> $objectData
	 */
	private function updateImportedObject(string $collection, string $id, array $objectData): ObjectData
	{
		$this->eventDispatcher->suspendForImport($collection);
		try {
			$objectData['id'] = $id;
			// preserveDates: without it every sync overwrite restamps onUpdate
			// fields to import time, making the synced copy read as "newer"
			// than the source it mirrors — which would poison any freshness
			// comparison between the two sides forever after.
			$object = $this->objectUpdater->updateObject($collection, $id, $objectData, preserveDates: true);
			$this->eventDispatcher->dispatch(
				CoreEvent::IMPORT_UPDATED,
				new ObjectEventPayload($collection, $object->id, $object),
			);

			return $object;
		} finally {
			$this->eventDispatcher->resumeForImport($collection);
		}
	}

	private function addError(string $message): void
	{
		$this->errors[] = $message;
		$this->logger->error($message);
	}

	/**
	 * Refuse a write targeting a code-executing system collection (e.g.
	 * `automations`) unless this import was explicitly authorized for it.
	 * Records an error and returns true when the write must be skipped, so the
	 * rest of the import can proceed and the caller still sees a failure result.
	 */
	private function refuseSystemCollection(string $id, string $kind): bool
	{
		if ($this->allowSystemCollections || !SchemaData::isSystemCollection($id)) {
			return false;
		}

		$this->addError(sprintf(
			'%s %s: refused — the "%s" collection executes code and can only be imported by a super-admin',
			$kind,
			$id,
			$id,
		));

		return true;
	}

	private function addResult(string $message): void
	{
		$this->results[] = $message;
		$this->logger->info($message);
	}

	/**
	 * @param string $filePath               Path to the jumpstart JSON file
	 * @param bool   $allowSystemCollections  See $this->allowSystemCollections — pass true only for shell-trusted callers (CLI)
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function importFromFile(string $filePath, bool $allowSystemCollections = false): OperationResult
	{
		if (!file_exists($filePath)) {
			throw new \Exception("Jumpstart file not found: {$filePath}");
		}

		$content = file_get_contents($filePath);
		if ($content === false) {
			throw new \Exception("Failed to read jumpstart file: {$filePath}");
		}

		$definition = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \Exception('Invalid JSON in jumpstart file: ' . json_last_error_msg());
		}

		return $this->importFromDefinition($definition, false, $allowSystemCollections);
	}

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function importDemoDefinition(bool $allowSystemCollections = false): OperationResult
	{
		return $this->importFromFile($this->demoJumpstartFile(), $allowSystemCollections);
	}

	/**
	 * Import a JumpStart definition.
	 *
	 * When `$upsert` is false (default) the importer preserves the
	 * starter-kit semantics: an object that already exists in the target
	 * collection is left untouched and logged as "skipping". That's the
	 * right behaviour for `tcms jumpstart:import` and for the public
	 * `POST /api/import/jumpstart` endpoint, where the operator's edits
	 * must not be trampled by a re-run of a starter kit.
	 *
	 * When `$upsert` is true (sync push / sync pull) the importer treats
	 * the incoming payload as authoritative: existing objects are
	 * overwritten via ObjectUpdater. This is the mode the dedicated
	 * `POST /api/sync/import` route turns on so a sync push from a local
	 * environment lands a true mirror of local on the remote.
	 *
	 * @param array<string, mixed> $definition
	 * @param bool                 $allowSystemCollections  See $this->allowSystemCollections — true only for super-admin/shell-trusted callers
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function importFromDefinition(array $definition, bool $upsert = false, bool $allowSystemCollections = false): OperationResult
	{
		$this->results                = [];
		$this->errors                 = [];
		$this->allowSystemCollections = $allowSystemCollections;
		$this->upsert                 = $upsert;

		// Increase execution time for image generation
		set_time_limit(300); // 5 minutes

		$this->logger->info('Starting jumpstart import', [
			'name'    => $definition['name'] ?? 'Unknown',
			'version' => $definition['version'] ?? 'Unknown',
			'upsert'  => $upsert,
		]);

		// Need to process in this order to ensure dependencies are met
		if (isset($definition['schemas'])) {
			$this->processSchemas($definition['schemas']);
		}
		if (isset($definition['collections'])) {
			$this->processCollections($definition['collections']);
		}
		if (isset($definition['templates'])) {
			$this->processTemplates($definition['templates']);
		}
		if (isset($definition['objects'])) {
			$this->processObjects($definition['objects'], $upsert);
		}
		if (isset($definition['factory'])) {
			$this->processFactory($definition['factory']);
		}
		// Last, and deliberately so: the order arrived with the collection
		// settings but can only be applied once the pages it arranges exist.
		$this->applyPendingPageOrder();

		$data = [
			'results' => $this->results,
			'errors'  => $this->errors,
			'summary' => $this->generateSummary(),
		];

		if ($this->errors !== []) {
			return OperationResult::failure('Import completed with errors', null, $data);
		}

		return OperationResult::success('Import completed successfully', $data);
	}

	/**
	 * @param array<int, array<string, mixed>> $schemas
	 */
	private function processSchemas(array $schemas): void
	{
		foreach ($schemas as $schema) {
			if ($this->refuseSystemCollection((string)($schema['id'] ?? ''), 'Schema')) {
				continue;
			}
			try {
				// saveSchema overwrites unconditionally; in sync mode, snapshot
				// the existing version first so the overwrite has an undo.
				if ($this->upsert) {
					$this->syncBackup->backupSchema((string)($schema['id'] ?? ''));
				}
				// preserveDates: an imported schema keeps its source's updated
				// timestamp — restamping would make the copy read newer than
				// the original (see the same rule on object imports below).
				$this->schemaSaver->saveSchema($schema, preserveDates: true);
				$this->addResult(sprintf('Schema %s: created', $schema['id'] ?? 'unknown'));
			} catch (\Exception $e) {
				$this->addError(sprintf('Schema %s: %s', $schema['id'] ?? 'unknown', $e->getMessage()));
			}
		}
	}

	/**
	 * Lift `pageOrder` off each collection entry and hold it until the pages
	 * exist.
	 *
	 * The order travels inside the collection's settings — it is configuration,
	 * so it belongs to the same selection the operator makes for settings —
	 * but it cannot be APPLIED there. Collections are processed before objects,
	 * and BuilderOrderService::write() reconciles the tree against the pages
	 * that currently exist, so an order written at collection time would have
	 * every id stripped out as unknown and the arrangement lost.
	 *
	 * Stripping the key also keeps it out of CollectionSaver, which would
	 * otherwise be handed a field that is not part of CollectionData.
	 *
	 * @param array<string, mixed> $collections
	 *
	 * @return array<string, mixed>
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
					$this->pendingPageOrder[$id] = $this->normalizeOrderTree($entry['pageOrder']);
				}

				unset($entry['pageOrder']);
				$collections[$kind][$index] = $entry;
			}
		}

		return $collections;
	}

	/**
	 * Coerce an incoming order tree into the shape BuilderOrderService expects.
	 *
	 * The payload arrives from a remote, so nothing about its shape is
	 * guaranteed — non-array nodes are dropped and keys are normalised to
	 * strings rather than trusted. BuilderOrderService::write() reconciles the
	 * ids afterwards; this only guarantees the container shape.
	 *
	 * @param array<mixed> $tree
	 *
	 * @return list<array<string,mixed>>
	 */
	private function normalizeOrderTree(array $tree): array
	{
		$clean = [];

		foreach ($tree as $node) {
			if (!is_array($node)) {
				continue;
			}

			$normalized = [];
			foreach ($node as $key => $value) {
				$normalized[(string)$key] = $value;
			}

			$clean[] = $normalized;
		}

		return $clean;
	}

	/**
	 * Apply the page orders held back by stashPageOrder(), once the pages they
	 * describe have been imported.
	 *
	 * Writes through BuilderOrderService rather than the repository so the
	 * incoming tree is reconciled against this site's pages: ids the
	 * destination does not have are dropped, and pages it has that the tree
	 * omits are appended, so a partial or stale tree cannot orphan a page.
	 */
	private function applyPendingPageOrder(): void
	{
		foreach ($this->pendingPageOrder as $collectionId => $tree) {
			if ($this->refuseSystemCollection($collectionId, 'Page order')) {
				continue;
			}

			try {
				/** @var list<array<string,mixed>> $tree */
				$this->orderService->write($collectionId, $tree);
				$this->addResult(sprintf('Page order %s: applied', $collectionId));
			} catch (\Throwable $e) {
				$this->addError(sprintf('Page order %s: %s', $collectionId, $e->getMessage()));
			}
		}

		$this->pendingPageOrder = [];
	}

	/**
	 * @param array<int, array<string, string>> $templates
	 */
	private function processTemplates(array $templates): void
	{
		foreach ($templates as $template) {
			$templateId = $template['id'] ?? 'unknown';
			try {
				$this->templateSaver->saveTemplate($templateId, $template['template'] ?? '');
				$this->addResult(sprintf('Template %s: created', $templateId));
			} catch (\Exception $e) {
				$this->addError(sprintf('Template %s: %s', $templateId, $e->getMessage()));
			}
		}
	}

	/** @param array<string, mixed> $collections */
	private function processCollections(array $collections): void
	{
		$collections = $this->stashPageOrder($collections);

		// Process custom collections
		if (isset($collections['custom'])) {
			foreach ($collections['custom'] as $collectionDef) {
				if ($this->refuseSystemCollection((string)($collectionDef['id'] ?? ''), 'Collection')) {
					continue;
				}
				try {
					$this->createCustomCollection($collectionDef);
				} catch (\Exception $e) {
					$this->addError(sprintf('Collection %s: %s', $collectionDef['id'] ?? 'unknown', $e->getMessage()));
				}
			}
		}

		// Process reserved collections. Entries can be either:
		//   - a string id ("blog")               -> create with defaults
		//   - an object with id + overrides      -> create, then patch
		// The object form lets starters set things like `url`, `prettyUrl`,
		// `sortBy`, `name`, etc. on a reserved collection without losing the
		// built-in schema binding.
		if (isset($collections['reserved'])) {
			foreach ($collections['reserved'] as $entry) {
				$id = is_string($entry) ? $entry : (string)($entry['id'] ?? 'unknown');
				if ($this->refuseSystemCollection($id, 'Collection')) {
					continue;
				}
				try {
					$this->createReservedCollection($entry);
				} catch (\Exception $e) {
					$this->addError(sprintf('Collection %s: %s', $id, $e->getMessage()));
				}
			}
		}
	}

	/** @param string|array<string,mixed> $entry */
	private function createReservedCollection(string|array $entry): void
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
			if ($overrides !== [] && $this->upsert && $existed) {
				// Sync mode against an existing collection: the entry is the
				// source's full settings — mirror them (including clearing
				// keys the source emptied), never the local counters.
				$this->upsertCollectionMeta($id, $entry, $collection);

				return;
			}
			if ($overrides !== []) {
				// Starter-kit semantics: shallow patch of the overrides on
				// top of defaults, without touching the schema binding.
				$this->collectionSaver->patchCollection($id, $overrides);
			}
		}

		$this->addResult(sprintf('Collection %s: %s', $collection->id, $existed ? 'exists' : 'created'));
	}

	/** @param array<string, mixed> $collectionDef */
	private function createCustomCollection(array $collectionDef): void
	{
		$id       = (string)($collectionDef['id'] ?? '');
		$existing = $id !== '' ? $this->collectionFetcher->fetchCollection($id) : null;

		if ($this->upsert && $existing instanceof CollectionData) {
			$this->upsertCollectionMeta($id, $collectionDef, $existing);

			return;
		}

		// preserveDates: an imported collection keeps its source's `updated`
		// (settings) timestamp — restamping would make the copy read newer
		// than the original (same rule as schemas and objects).
		$collection = $this->collectionSaver->saveCollection($this->stripComputedCollectionFields($collectionDef), preserveDates: true);
		$this->addResult(sprintf('Collection %s: created', $collection->id));
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
	 * @param array<string,mixed> $incoming
	 */
	private function upsertCollectionMeta(string $collectionId, array $incoming, CollectionData $existing): void
	{
		$this->syncBackup->backupCollectionMeta($collectionId);

		$data = array_merge($existing->toArray(), $this->stripComputedCollectionFields($incoming));

		$this->collectionSaver->updateCollection($collectionId, $data, $existing, preserveDates: true);
		$this->addResult(sprintf('Collection %s: updated', $collectionId));
	}

	/**
	 * The environment-local computed fields sync must never carry into a
	 * write: `count` (lifetime oid counter), `totalObjects`, `lastUpdated`
	 * (content timestamp). The exporter already strips them; stripping again
	 * here enforces the rule against any hand-built payload.
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

	/** @param array<int,array<string,mixed>> $objects */
	/**
	 * @param array<int,array<string,mixed>> $objects
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	private function processObjects(array $objects, bool $upsert): void
	{
		// Track which objects landed where so we can fire one import.completed
		// per touched collection at the end. saveImportedObject() /
		// updateImportedObject() suspend `object.created` and `object.updated`
		// respectively, which means IndexBuildListener doesn't see the
		// per-object events. Without the completion signal the receiving end
		// ends up with the objects on disk but a stale index — sync push
		// appears to succeed but imported pages/prompts/etc. don't show up
		// in lists, search, or queries until the next manual reindex.
		/** @var array<string,array{created:list<string>,updated:list<string>,count:int}> $touchedByCollection */
		$touchedByCollection = [];

		foreach ($objects as $objectDef) {
			$collectionId = $objectDef['collection'] ?? '';
			$objectId     = $objectDef['id'] ?? '';
			$objectData   = $objectDef['data'] ?? [];
			try {
				$action = $this->processObject($collectionId, $objectId, $objectData, $upsert);
				if ($collectionId !== '' && $action !== 'skipped') {
					$touchedByCollection[$collectionId] ??= ['created' => [], 'updated' => [], 'count' => 0];
					$touchedByCollection[$collectionId]['count']++;
					if ($action === 'created') {
						$touchedByCollection[$collectionId]['created'][] = (string)$objectId;
					} elseif ($action === 'updated') {
						$touchedByCollection[$collectionId]['updated'][] = (string)$objectId;
					}
				}
			} catch (\Exception $e) {
				$this->addError(sprintf('Object %s/%s: %s', $collectionId, $objectId, $e->getMessage()));
			}
		}

		foreach ($touchedByCollection as $collectionId => $stats) {
			$this->eventDispatcher->dispatch(
				CoreEvent::IMPORT_COMPLETED,
				new ImportEventPayload(
					$collectionId,
					$stats['count'],
					$stats['created'],
					$stats['updated'],
				),
			);
		}
	}

	/**
	 * @param array<string,mixed> $objectData
	 *
	 * @return 'created'|'updated'|'skipped' outcome of the write
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	private function processObject(string $collectionId, string $objectId, array $objectData, bool $upsert): string
	{
		if ($this->refuseSystemCollection($collectionId, 'Object')) {
			return 'skipped';
		}

		$collection = $this->collectionFetcher->fetchCollection($collectionId);

		if (!$collection instanceof CollectionData) {
			throw new \Exception('Collection not found');
		}

		$exists     = $this->objectFetcher->existsObject($collectionId, $objectId);
		$objectData = $this->generateFactoryObjectDataForImages($collectionId, $objectId, $objectData);

		if ($exists) {
			if (!$upsert) {
				$this->addResult(sprintf('Object %s/%s: already exists, skipping', $collectionId, $objectId));

				return 'skipped';
			}
			$this->syncBackup->backupObject($collectionId, $objectId);
			$objectData = $this->preserveUntravelledMedia($collectionId, $objectId, $collection->schema, $objectData);
			$this->updateImportedObject($collectionId, $objectId, $objectData);
			$this->addResult(sprintf('Object %s/%s: updated', $collectionId, $objectId));

			return 'updated';
		}

		$this->saveImportedObject($collectionId, $objectData);
		$this->addResult(sprintf('Object %s/%s: created', $collectionId, $objectId));

		return 'created';
	}

	/**
	 * Carry the destination's own image/gallery values through an upsert.
	 *
	 * The upsert path replaces the whole object, so a field the payload does
	 * not mention would be wiped. JumpStart carries no binaries, which means an
	 * image field is not syncable data in either direction: the source cannot
	 * send one (the file would not exist here) and must not clear one (it has
	 * no authority over media it never received). The destination owns these
	 * fields, and images are managed in the admin rather than through sync.
	 *
	 * Only fields ABSENT from the payload are restored. A value that is present
	 * is an authored factory rule, and those are still honored — that is a
	 * deliberate feature of hand-written starter kits, and the reason the
	 * exporter now omits the key rather than writing a type name that was
	 * indistinguishable from one.
	 *
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,mixed>
	 */
	private function preserveUntravelledMedia(
		string $collectionId,
		string $objectId,
		string $schemaId,
		array $objectData,
	): array {
		try {
			$schema   = $this->schemaFetcher->fetchSchema($schemaId);
			$existing = $this->objectFetcher->fetchObject($collectionId, $objectId)->toArray();
		} catch (\Throwable $e) {
			// Non-fatal: without the schema or the current object there is
			// nothing to preserve, and failing the import over it would be a
			// worse outcome than an untouched media field.
			$this->logger->warning('Could not preserve media fields on import', [
				'collection' => $collectionId,
				'id'         => $objectId,
				'error'      => $e->getMessage(),
			]);

			return $objectData;
		}

		foreach ($schema->properties as $fieldName => $property) {
			$fieldType = $property['field'] ?? $property['type'] ?? '';

			if (!in_array($fieldType, ['image', 'gallery'], true)) {
				continue;
			}

			if (array_key_exists($fieldName, $objectData)) {
				continue;
			}

			if (array_key_exists($fieldName, $existing)) {
				$objectData[$fieldName] = $existing[$fieldName];
			}
		}

		return $objectData;
	}

	/**
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,mixed>
	 */
	private function generateFactoryObjectDataForImages(string $collectionId, string $objectId, array $objectData): array
	{
		// Imported objects are real data. JumpStart cannot carry image/gallery
		// binaries, so those may be written as faker rules in the object data —
		// but only rules the author wrote are honored. The collection's factory
		// definitions are deliberately NOT merged in: those are test-data rules,
		// and merging them here faker-filled every schema property missing from
		// the import (random toggles, placeholder images) instead of leaving
		// them to schema defaults.
		[$factoryRules, $staticData] = $this->splitFactoryRules($objectData, fn (string $rule): bool => (
			str_starts_with($rule, 'image') || str_starts_with($rule, 'gallery')
		));

		return $this->generateObjectData($collectionId, $objectId, $factoryRules, $staticData);
	}

	/**
	 * @param array<string,mixed> $factoryData
	 *
	 * @return array<string,mixed>
	 */
	private function generateFactoryObjectData(string $collectionId, string $objectId, array $factoryData): array
	{
		// Factory entries generate test data: merge the collection's factory
		// definitions so schema-declared rules fill unspecified properties.
		[$factoryRules, $staticData] = $this->splitFactoryRules($factoryData, null);

		$factoryRules = $this->factoryImporter->mergeFactoryDefinitions($collectionId, $factoryRules);

		return $this->generateObjectData($collectionId, $objectId, $factoryRules, $staticData);
	}

	/**
	 * Split object data into faker-rule values (matching the optional filter)
	 * and static values.
	 *
	 * @param array<string,mixed>              $objectData
	 * @param callable(string):bool|null $filterFakerRule
	 *
	 * @return array{0:array<string,string>,1:array<string,mixed>}
	 */
	private function splitFactoryRules(array $objectData, ?callable $filterFakerRule): array
	{
		$factoryRules = [];
		$staticData   = [];

		foreach ($objectData as $property => $value) {
			if (is_string($value) && $this->factoryImporter->isFakerRule($value) && ($filterFakerRule === null || $filterFakerRule($value))) {
				$factoryRules[$property] = $value;
				continue;
			}
			$staticData[$property] = $value;
		}

		return [$factoryRules, $staticData];
	}

	/**
	 * @param array<string,string> $factoryRules
	 * @param array<string,mixed>  $staticData
	 *
	 * @return array<string,mixed>
	 */
	private function generateObjectData(string $collectionId, string $objectId, array $factoryRules, array $staticData): array
	{
		$generatedData = $this->factoryImporter->generateFakeObject($collectionId, $factoryRules, $objectId);

		return array_merge($generatedData, $staticData);
	}

	/** @param array<int,array<string,mixed>> $factoryItems */
	private function processFactory(array $factoryItems): void
	{
		foreach ($factoryItems as $factoryDef) {
			$collectionId = $factoryDef['collection'];
			$factoryData  = $factoryDef['data'] ?? [];
			$factoryId    = $factoryDef['id'] ?? '';

			if ($this->refuseSystemCollection((string)$collectionId, 'Factory')) {
				continue;
			}

			try {
				// Check if this is a specific ID factory item
				if (!empty($factoryId)) {
					$this->processSpecificFactoryObject($collectionId, $factoryId, $factoryData);
					continue;
				}
				// Regular bulk factory generation
				$count = $factoryDef['count'] ?? 1;
				$this->processBulkFactoryGeneration($collectionId, $count, $factoryData);
			} catch (\Exception $e) {
				$this->addError(sprintf('Factory %s/%s: %s', $collectionId, $factoryId, $e->getMessage()));
			}
		}
	}

	/** @param array<string,mixed> $factoryData */
	private function processSpecificFactoryObject(string $collectionId, string $objectId, array $factoryData): void
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionId);

		if (!$collection instanceof CollectionData) {
			throw new \Exception('Collection not found');
		}

		// Check if object already exists
		if ($this->objectFetcher->existsObject($collectionId, $objectId)) {
			$this->addResult(sprintf('Factory %s/%s: already exists, skipping', $collectionId, $objectId));

			return;
		}

		// Generate object data using the same pattern as processObjects
		$objectData = $this->generateFactoryObjectData($collectionId, $objectId, $factoryData);
		$this->saveImportedObject($collectionId, $objectData);
		$this->addResult(sprintf('Factory %s/%s: generated', $collectionId, $objectId));
	}

	/** @param array<string, mixed> $factoryData */
	private function processBulkFactoryGeneration(string $collectionId, int $count, array $factoryData): void
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionId);

		if (!$collection instanceof CollectionData) {
			throw new \Exception('Collection not found');
		}

		// Use the same pattern as processObjects for merging factory definitions
		$finalFactoryDefs = $this->factoryImporter->mergeFactoryDefinitions($collectionId, $factoryData);

		// Import using FactoryImporter
		$imported = $this->factoryImporter->import($collectionId, $count, $finalFactoryDefs);

		$this->addResult(sprintf('Factory %s: generated %d items', $collectionId, $imported));
	}

	/** @return array<string,int> */
	private function generateSummary(): array
	{
		$summary = [
			'schemas_created'       => 0,
			'collections_created'   => 0,
			'templates_created'     => 0,
			'objects_created'       => 0,
			'factory_items_created' => 0,
			'total_errors'          => count($this->errors),
		];

		foreach ($this->results as $result) {
			if (str_starts_with($result, 'Schema ')) {
				$summary['schemas_created']++;
			} elseif (str_starts_with($result, 'Collection ')) {
				$summary['collections_created']++;
			} elseif (str_starts_with($result, 'Template ')) {
				$summary['templates_created']++;
			} elseif (str_starts_with($result, 'Object ')) {
				$summary['objects_created']++;
			} elseif (str_starts_with($result, 'Factory ')) {
				// Extract count from factory messages like "Factory blog: generated 5 items"
				if (preg_match('/generated (\d+) items/', $result, $matches)) {
					$summary['factory_items_created'] += (int)$matches[1];
					continue;
				}
				$summary['factory_items_created']++;
			}
		}

		return $summary;
	}
}
