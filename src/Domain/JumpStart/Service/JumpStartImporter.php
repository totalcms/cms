<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
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

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CollectionSaver $collectionSaver,
		private readonly ObjectFetcher $objectFetcher,
		private readonly ObjectSaver $objectSaver,
		private readonly ObjectUpdater $objectUpdater,
		private readonly SchemaSaver $schemaSaver,
		private readonly TemplateSaver $templateSaver,
		private readonly FactoryImporter $factoryImporter,
		private readonly \TotalCMS\Domain\Event\Service\EventDispatcher $eventDispatcher,
		private readonly \TotalCMS\Domain\Sync\Service\SyncBackupService $syncBackup,
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
	private function saveImportedObject(string $collection, array $objectData): \TotalCMS\Domain\Object\Data\ObjectData
	{
		$this->eventDispatcher->suspendForImport($collection);
		try {
			// preserveDates: imported data is the authoritative history of its
			// source object — authored created/updated values travel verbatim,
			// empty ones still stamp normally.
			$object = $this->objectSaver->saveObject($collection, $objectData, preserveDates: true);
			$this->eventDispatcher->dispatch(
				CoreEvent::IMPORT_CREATED,
				new \TotalCMS\Domain\Event\Payload\ObjectEventPayload($collection, $object->id, $object),
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
	private function updateImportedObject(string $collection, string $id, array $objectData): \TotalCMS\Domain\Object\Data\ObjectData
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
				new \TotalCMS\Domain\Event\Payload\ObjectEventPayload($collection, $object->id, $object),
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

		$collection = $this->collectionFetcher->fetchOrCreateReserved($id);
		if (!$collection instanceof CollectionData) {
			throw new \Exception("Error creating Reserved Collection: {$id}");
		}

		// Apply optional overrides (url, prettyUrl, sortBy, etc.) without
		// touching the underlying schema binding.
		if (is_array($entry)) {
			$overrides = $entry;
			unset($overrides['id']);
			if ($overrides !== []) {
				$this->collectionSaver->patchCollection($id, $overrides);
			}
		}

		$this->addResult(sprintf('Collection %s: created', $collection->id));
	}

	/** @param array<string, mixed> $collectionDef */
	private function createCustomCollection(array $collectionDef): void
	{
		$collection = $this->collectionSaver->saveCollection($collectionDef);
		$this->addResult(sprintf('Collection %s: created', $collection->id));
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
				new \TotalCMS\Domain\Event\Payload\ImportEventPayload(
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
			$this->updateImportedObject($collectionId, $objectId, $objectData);
			$this->addResult(sprintf('Object %s/%s: updated', $collectionId, $objectId));

			return 'updated';
		}

		$this->saveImportedObject($collectionId, $objectData);
		$this->addResult(sprintf('Object %s/%s: created', $collectionId, $objectId));

		return 'created';
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
