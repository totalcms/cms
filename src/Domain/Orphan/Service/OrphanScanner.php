<?php

namespace TotalCMS\Domain\Orphan\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Orphan\Data\OrphanEntry;
use TotalCMS\Domain\Orphan\Data\OrphanReport;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

readonly class OrphanScanner
{
	private const GC_BATCH_SIZE = 100;

	private LoggerInterface $logger;

	public function __construct(
		private CollectionRepository $collectionRepository,
		private SchemaFetcher $schemaFetcher,
		private IndexReader $indexReader,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('orphan-scanner');
	}

	/**
	 * Find all relational properties across all collections.
	 *
	 * @return array<int,array{source:string,property:string,target:string,value:string,isArray:bool}>
	 */
	public function findRelationalProperties(?string $filterCollection = null): array
	{
		$collections = $this->collectionRepository->listAllCollections();
		$relational  = [];

		foreach ($collections as $collection) {
			if ($filterCollection !== null && $collection->id !== $filterCollection) {
				continue;
			}

			try {
				$schema = $this->schemaFetcher->fetchSchemaForCollection($collection->id);
			} catch (\Exception $e) {
				$this->logger->warning('Failed to fetch schema for collection', [
					'collection' => $collection->id,
					'error'      => $e->getMessage(),
				]);
				continue;
			}

			foreach ($schema->properties as $propName => $propDef) {
				if (!is_array($propDef)) {
					continue;
				}

				$settings = $propDef['settings'] ?? [];
				if (!is_array($settings)) {
					continue;
				}

				$relOpts = $settings['relationalOptions'] ?? null;
				if (!is_array($relOpts)) {
					continue;
				}

				// Skip view-based relational options (views are derived data)
				if (isset($relOpts['view']) && $relOpts['view'] !== '') {
					continue;
				}

				$targetCollection = $relOpts['collection'] ?? '';
				if ($targetCollection === '') {
					continue;
				}

				$valueField = trim($relOpts['value'] ?? 'id');
				$isArray    = ($propDef['type'] ?? '') === 'array';

				$relational[] = [
					'source'   => $collection->id,
					'property' => $propName,
					'target'   => $targetCollection,
					'value'    => $valueField,
					'isArray'  => $isArray,
				];
			}
		}

		return $relational;
	}

	/**
	 * Scan all collections for orphaned relational references.
	 */
	public function scanAll(): OrphanReport
	{
		$this->logger->info('Starting full orphan scan');

		return $this->performScan(null);
	}

	/**
	 * Scan a single collection for orphaned relational references.
	 */
	public function scanCollection(string $collection): OrphanReport
	{
		$this->logger->info('Starting orphan scan for collection', ['collection' => $collection]);

		return $this->performScan($collection);
	}

	private function performScan(?string $filterCollection): OrphanReport
	{
		$report     = new OrphanReport();
		$relational = $this->findRelationalProperties($filterCollection);

		$report->relationalPropertiesFound = count($relational);

		if ($relational === []) {
			$this->logger->info('No relational properties found');

			return $report;
		}

		// Build valid-IDs cache per target collection
		/** @var array<string,array<string,true>> $validIdsCache */
		$validIdsCache      = [];
		$scannedCollections = [];

		foreach ($relational as $rel) {
			$target = $rel['target'];
			if (!isset($validIdsCache[$target])) {
				try {
					$index = $this->indexReader->fetchIndex($target);
					$ids   = $index->objects->pluck($rel['value'])->filter()->all();
					// Use associative array for O(1) lookups
					$validIdsCache[$target] = array_fill_keys(
						array_map(strval(...), $ids),
						true
					);
				} catch (\Exception $e) {
					$this->logger->warning('Failed to fetch index for target collection', [
						'collection' => $target,
						'error'      => $e->getMessage(),
					]);
					// Target collection doesn't exist — all references are orphaned
					$validIdsCache[$target] = [];
				}
			}

			$source = $rel['source'];
			if (!isset($scannedCollections[$source])) {
				$scannedCollections[$source] = true;
			}
		}

		$report->collectionsScanned = count($scannedCollections);

		// Group relational properties by source collection for efficient scanning
		/** @var array<string,array<int,array{property:string,target:string,value:string,isArray:bool}>> $bySource */
		$bySource = [];
		foreach ($relational as $rel) {
			$bySource[$rel['source']][] = $rel;
		}

		$objectCount = 0;

		foreach ($bySource as $sourceCollection => $properties) {
			try {
				$index   = $this->indexReader->fetchIndex($sourceCollection);
				$objects = $index->objects;
			} catch (\Exception $e) {
				$this->logger->warning('Failed to fetch index for source collection', [
					'collection' => $sourceCollection,
					'error'      => $e->getMessage(),
				]);
				continue;
			}

			foreach ($objects as $obj) {
				$objectId = (string)($obj['id'] ?? '');
				if ($objectId === '') {
					continue;
				}

				$report->objectsScanned++;
				$objectCount++;

				foreach ($properties as $rel) {
					$propName = $rel['property'];

					// Check if this property is in the index data
					$value = $obj[$propName] ?? null;
					if (in_array($value, [null, '', []], true)) {
						continue;
					}

					$validIds  = $validIdsCache[$rel['target']] ?? [];
					$isArray   = $rel['isArray'];
					$orphanIds = [];

					if ($isArray && is_array($value)) {
						foreach ($value as $refId) {
							$refIdStr = (string)$refId;
							if ($refIdStr !== '' && !isset($validIds[$refIdStr])) {
								$orphanIds[] = $refIdStr;
							}
						}
					} else {
						$refIdStr = (string)$value;
						if ($refIdStr !== '' && !isset($validIds[$refIdStr])) {
							$orphanIds[] = $refIdStr;
						}
					}

					if ($orphanIds !== []) {
						$report->addEntry(new OrphanEntry(
							collection: $sourceCollection,
							objectId: $objectId,
							property: $propName,
							orphanedIds: $orphanIds,
							isArray: $isArray,
							targetCollection: $rel['target'],
						));
					}
				}

				// GC every batch to manage memory
				if ($objectCount % self::GC_BATCH_SIZE === 0) {
					gc_collect_cycles();
				}
			}

			unset($objects, $index);
		}

		$this->logger->info('Orphan scan complete', [
			'collectionsScanned'      => $report->collectionsScanned,
			'objectsScanned'          => $report->objectsScanned,
			'orphanedReferencesFound' => $report->orphanedReferencesFound,
		]);

		return $report;
	}
}
