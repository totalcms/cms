<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
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
	 */
	public function exportSyncData(
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
	): JumpStartData {
		$this->logger->info('Starting sync data export');

		$this->cacheManager->clearAllCaches();

		$this->exportCustomSchemas($schemaFilter);
		$this->exportTemplates($templateFilter);
		$this->exportSyncCollectionObjects($collectionsFilter);

		$this->logger->info('Completed sync data export', [
			'schemas'   => count($this->jumpstart->schemas),
			'templates' => count($this->jumpstart->templates),
			'objects'   => count($this->jumpstart->objects),
		]);

		return $this->jumpstart;
	}

	/**
	 * Export current CMS data to jumpstart definition.
	 */
	public function exportCurrentData(): JumpStartData
	{
		$this->logger->info('Starting jumpstart export');

		// Make sure we do not have any cached data
		$this->cacheManager->clearAllCaches();

		$this->exportCustomSchemas();
		$this->exportCollections();
		$this->exportObjects();
		$this->exportTemplates();

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

	private function exportCollections(): void
	{
		$collections = $this->collectionLister->listAllCollections();

		foreach ($collections as $collection) {
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

	private function exportObjects(): void
	{
		$collections = $this->collectionLister->listAllCollections();

		foreach ($collections as $collection) {
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
			if (isset($processedData[$fieldName])) {
				$fieldType = $property['field'] ?? $property['type'] ?? '';

				switch ($fieldType) {
					case 'image':
					case 'gallery':
						// Normalize image and gallery properties to their respective types
						$processedData[$fieldName] = $fieldType;
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
			} catch (\Exception $e) {
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
