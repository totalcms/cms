<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Builder\Repository\BuilderOrderRepository;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Data\JumpStartExportOptions;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Playground\Data\PlaygroundData;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Sync\Data\SyncableCollections;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

readonly class JumpStartExporter
{
	private LoggerInterface $logger;

	public function __construct(
		private CollectionLister $collectionLister,
		private SchemaLister $schemaLister,
		private SchemaFetcher $schemaFetcher,
		private ObjectFetcher $objectFetcher,
		private IndexReader $indexReader,
		private TemplateLister $templateLister,
		private TemplateFetcher $templateFetcher,
		private JumpStartData $jumpstart,
		private CacheManager $cacheManager,
		private BuilderOrderRepository $orderRepository,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::JumpStartExporter);
	}

	public function setMetadata(string $name = '', string $description = ''): void
	{
		if ($name !== '') {
			$this->jumpstart->setName($name);
		}
		if ($description !== '') {
			$this->jumpstart->setDescription($description);
		}
	}

	/**
	 * Export schemas, templates, and objects from sync-allowlisted collections
	 * (push/pull sync).
	 *
	 * Schema/template filters use the existing tristate:
	 *   - null  → export all of this category
	 *   - []    → export none of this category
	 *   - list  → export only those ids
	 *
	 * Collections use a per-collection map because the Sync Manager UI
	 * surfaces each allowlisted collection (Pages, Mailer, Prompts,
	 * Dataviews) as its own section with its own all/specific/none
	 * picker over the object ids inside it:
	 *   - null               → export every object from every allowlisted
	 *                          collection (CLI/back-compat default)
	 *   - ['id' => null]     → export every object in that collection
	 *   - ['id' => [...ids]] → export only those object ids
	 *   - key absent         → skip that collection entirely
	 * Any collection id outside SyncableCollections::IDS is silently
	 * dropped before export (defense in depth).
	 *
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 * @param list<string>|null                       $collectionMetaFilter Tristate: null = every
	 *                                                                      local collection's settings,
	 *                                                                      [] = none, list = those ids
	 */
	public function exportSyncData(
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
		?array $collectionMetaFilter = null,
	): JumpStartData {
		$this->logger->info('Starting sync data export');

		$this->cacheManager->clearAllCaches();

		$this->exportCustomSchemas($schemaFilter);
		$this->exportTemplates($templateFilter);
		$this->exportSyncCollectionObjects($collectionsFilter);
		$this->exportSyncCollectionMeta($collectionMetaFilter);

		$this->logger->info('Completed sync data export', [
			'schemas'     => count($this->jumpstart->schemas),
			'templates'   => count($this->jumpstart->templates),
			'objects'     => count($this->jumpstart->objects),
			'collections' => count($this->jumpstart->collections['custom']) + count($this->jumpstart->collections['reserved']),
		]);

		return $this->jumpstart;
	}

	/**
	 * Export collection SETTINGS (the .meta.json config) for sync — never
	 * objects, and never the environment-local computed fields (`count`,
	 * `totalObjects`, `lastUpdated`), which the receiving side must keep.
	 *
	 * Unlike the starter-kit export, the six conditional config keys are
	 * emitted explicitly even when empty: sync is a mirror, so an emptied
	 * MCP card on the source must be able to clear the target's. `updated`
	 * (the settings timestamp) travels — it's the freshness signal the
	 * diff hints on.
	 *
	 * Custom collections use the `collections.custom` shape; reserved-schema
	 * collections use the object form of `collections.reserved` so their
	 * settings survive (the bare-string form seeds defaults only).
	 *
	 * The Twig Playground's collection is excluded unconditionally. It is a
	 * per-install scratchpad, created on demand by whichever environment
	 * someone happens to open the tool on, so mirroring the container reports
	 * a difference the operator can neither act on (the UI's list is built
	 * from LOCAL collections, so a remote-only one is never selectable) nor
	 * would ever want to resolve. Its objects are already excluded from sync
	 * via SyncableCollections; the settings now match.
	 *
	 * @param list<string>|null $filter Tristate: null = all, [] = none, list = ids
	 */
	private function exportSyncCollectionMeta(?array $filter): void
	{
		if ($filter === []) {
			return;
		}

		foreach ($this->collectionLister->listAllCollections() as $collection) {
			if ($collection->id === PlaygroundData::COLLECTION_ID) {
				continue;
			}
			if ($filter !== null && !in_array($collection->id, $filter, true)) {
				continue;
			}

			$config = $collection->toArray();
			unset($config['count'], $config['totalObjects'], $config['lastUpdated']);

			// Mirror semantics: absent-when-empty keys must be explicit so
			// the importer can clear them on the receiving side.
			foreach (['properties', 'customProperties', 'formSettings', 'manualSort', 'sitemap', 'mcp'] as $key) {
				$config[$key] ??= [];
			}

			// The sidebar order / page hierarchy rides with the collection's
			// settings. It lives in its own file (.order.json, owned by
			// BuilderOrderRepository) rather than in CollectionData, but it is
			// configuration rather than content, so it belongs to the same
			// selection the operator makes for settings: tick Pages under
			// Collection Settings and its arrangement travels with them. That
			// also means a reorder shows up as the collection differing,
			// instead of needing a category of its own that would only ever
			// hold one row.
			//
			// Read through the repository, not BuilderOrderService: the
			// service migrates and WRITES an order file when none exists, and
			// an export must never mutate the site it is reading.
			if ($this->orderRepository->exists($collection->id)) {
				$config['pageOrder'] = $this->orderRepository->read($collection->id);
			}

			if (in_array($collection->schema, SchemaData::RESERVED_SCHEMAS)) {
				$this->jumpstart->addReservedCollection($config);
			} else {
				$this->jumpstart->addCustomCollection($config);
			}
		}
	}

	/**
	 * Export current CMS data to jumpstart definition.
	 *
	 * Pass a {@see JumpStartExportOptions} to restrict which categories/ids are
	 * included. Each filter uses the tristate contract:
	 *   - null  → export everything in that category (default)
	 *   - []    → export nothing in that category
	 *   - list  → export only those ids
	 */
	public function exportCurrentData(?JumpStartExportOptions $options = null): JumpStartData
	{
		$options ??= new JumpStartExportOptions();

		$this->logger->info('Starting jumpstart export');

		// Make sure we do not have any cached data
		$this->cacheManager->clearAllCaches();

		$this->exportCustomSchemas($options->schemaFilter);
		$this->exportCollections($options->collectionFilter);
		$this->exportObjects($options->objectFilter);
		$this->exportTemplates($options->templateFilter);

		$this->logger->info('Completed jumpstart export', [
			'schemas'              => count($this->jumpstart->schemas),
			'reserved_collections' => count($this->jumpstart->collections['reserved']),
			'custom_collections'   => count($this->jumpstart->collections['custom']),
			'objects'              => count($this->jumpstart->objects),
			'templates'            => count($this->jumpstart->templates),
		]);

		return $this->jumpstart;
	}

	/** @param list<string>|null $filter */
	private function exportCustomSchemas(?array $filter = null): void
	{
		$schemas = $this->schemaLister->listCustomSchemas();

		foreach ($schemas as $schema) {
			if ($filter !== null && !in_array($schema->id, $filter, true)) {
				continue;
			}
			$this->jumpstart->addSchema($schema->toArray());
		}
	}

	/** @param list<string>|null $filter */
	private function exportCollections(?array $filter = null): void
	{
		$collections = $this->collectionLister->listAllCollections();

		foreach ($collections as $collection) {
			if ($filter !== null && !in_array($collection->id, $filter, true)) {
				continue;
			}

			if (in_array($collection->schema, SchemaData::RESERVED_SCHEMAS)) {
				$this->jumpstart->addReservedCollection($collection->schema);
				continue;
			}

			// Exclude totalObjects and lastUpdated (these are calculated on import)
			$collectionData = $collection->toArray();
			unset($collectionData['totalObjects'], $collectionData['lastUpdated'], $collectionData['count']);

			$this->jumpstart->addCustomCollection($collectionData);
		}
	}

	/** @param list<string>|null $filter */
	private function exportObjects(?array $filter = null): void
	{
		$collections = $this->collectionLister->listAllCollections();

		foreach ($collections as $collection) {
			if ($filter !== null && !in_array($collection->id, $filter, true)) {
				continue;
			}

			$this->logger->info('Exporting objects from collection', [
				'collection' => $collection->id,
			]);

			// Use IndexReader to get all object IDs for this collection
			$index = $this->indexReader->fetchIndex($collection->id);

			$objectCount = 0;
			foreach ($index->objects as $object) {
				$object        = $this->objectFetcher->fetchObject($collection->id, $object['id']);
				$processedData = $this->processObjectData($object, $collection->schema);

				$this->jumpstart->addObject([
					'collection' => $collection->id,
					'id'         => $object->id,
					'data'       => $processedData,
				]);
				$objectCount++;
			}

			$this->logger->info('Completed object export for collection', [
				'collection'       => $collection->id,
				'objects_exported' => $objectCount,
			]);
		}
	}

	/**
	 * Export objects from the sync-allowlisted reserved collections only.
	 *
	 * Defense in depth: any collection id outside SyncableCollections::IDS
	 * is dropped before iteration. processObjectData() additionally
	 * normalises any image/file/depot/gallery fields to safe empty values,
	 * so a future schema change can't quietly start shipping binaries
	 * through the sync pipeline.
	 *
	 * @param array<string,list<string>|null>|null $filter
	 */
	private function exportSyncCollectionObjects(?array $filter): void
	{
		// Build the iteration target as a map of collectionId => idFilter.
		// null filter (CLI / back-compat) expands to "every allowlisted
		// collection, every object in it". An explicit map preserves only
		// allowlisted keys; missing keys mean the operator chose not to
		// sync that collection.
		if ($filter === null) {
			$targets = array_fill_keys(SyncableCollections::IDS, null);
		} else {
			$targets = [];
			foreach ($filter as $id => $idFilter) {
				if (in_array($id, SyncableCollections::IDS, true)) {
					$targets[$id] = $idFilter;
				}
			}
		}

		if ($targets === []) {
			return;
		}

		$collectionsById = [];
		foreach ($this->collectionLister->listAllCollections() as $candidate) {
			$collectionsById[$candidate->id] = $candidate;
		}

		foreach ($targets as $collectionId => $idFilter) {
			$collection = $collectionsById[$collectionId] ?? null;
			if ($collection === null) {
				$this->logger->info('Skipping sync of unknown allowlisted collection', [
					'collection' => $collectionId,
				]);
				continue;
			}

			try {
				$index = $this->indexReader->fetchIndex($collectionId);
			} catch (\Throwable $e) {
				$this->logger->warning('Skipping sync export — could not read collection index', [
					'collection' => $collectionId,
					'error'      => $e->getMessage(),
				]);
				continue;
			}

			$count = 0;
			foreach ($index->objects as $object) {
				$objectId = (string)($object['id'] ?? '');
				if ($idFilter !== null && !in_array($objectId, $idFilter, true)) {
					continue;
				}
				try {
					$fetched = $this->objectFetcher->fetchObject($collectionId, $objectId);
				} catch (\Throwable $e) {
					$this->logger->warning('Skipping sync export of object', [
						'collection' => $collectionId,
						'id'         => $objectId,
						'error'      => $e->getMessage(),
					]);
					continue;
				}

				$this->jumpstart->addObject([
					'collection' => $collectionId,
					'id'         => $fetched->id,
					'data'       => $this->processObjectData($fetched, $collection->schema),
				]);
				$count++;
			}

			$this->logger->info('Exported sync collection objects', [
				'collection' => $collectionId,
				'count'      => $count,
			]);
		}
	}

	/** @return array<string,mixed> */
	private function processObjectData(ObjectData $object, string $schemaId): array
	{
		$schema        = $this->schemaFetcher->fetchSchema($schemaId);
		$processedData = $object->toArray();

		// Process each field according to its schema type
		foreach ($schema->properties as $fieldName => $property) {
			$fieldType = $property['field'] ?? $property['type'] ?? '';

			if (in_array($fieldType, SchemaData::SENSITIVE_FIELD_TYPES, true)) {
				// Strip credential/secret values — never export password hashes or secrets
				unset($processedData[$fieldName]);
				continue;
			}

			if (isset($processedData[$fieldName])) {
				switch ($fieldType) {
					case 'image':
					case 'gallery':
						// Omitted, not normalized to the type name. Writing
						// "image" here collided with the factory-rule syntax the
						// importer honors, so every sync round-trip made the
						// destination FABRICATE a random placeholder image —
						// silently, and on every push, because the payload can
						// never say "this field is empty".
						//
						// Binaries do not travel, so an image reference must not
						// either: sending one would point the destination at a
						// file it does not have. Leaving the key out is the only
						// honest encoding of "this did not travel", and the
						// importer reads its absence as "keep what you have".
						unset($processedData[$fieldName]);
						break;

					case 'depot':
					case 'file':
						// Normalize depot and file properties to empty arrays
						$processedData[$fieldName] = [];
						break;
				}
			}
		}
		unset($processedData['id']);

		return $processedData;
	}

	/** @param list<string>|null $filter */
	private function exportTemplates(?array $filter = null): void
	{
		$this->logger->info('Exporting templates');

		// Export custom templates only (not reserved templates)
		$templates = $this->templateLister->listBuilderTemplates(null, true);

		foreach ($templates as $templatePath) {
			try {
				$template = $this->templateFetcher->fetchTemplate($templatePath);

				if ($filter !== null && !in_array($template->id, $filter, true)) {
					continue;
				}

				$this->jumpstart->addTemplate([
					'id'       => $template->id,
					'template' => $template->contents,
				]);
			} catch (\Throwable $e) {
				$this->logger->warning('Failed to export template', [
					'path'  => $templatePath,
					'error' => $e->getMessage(),
				]);
			}
		}

		$this->logger->info('Completed template export', [
			'templates_exported' => count($this->jumpstart->templates),
		]);
	}
}
