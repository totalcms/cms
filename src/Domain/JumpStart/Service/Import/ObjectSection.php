<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service\Import;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ImportEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\JumpStart\Data\ImportReport;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * The `objects` section of a JumpStart definition. Starter-kit mode leaves
 * an existing object untouched; sync mode (upsert) overwrites it, after a
 * backup and with the destination's own media carried through.
 */
readonly class ObjectSection
{
	private LoggerInterface $logger;

	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private ObjectFetcher $objectFetcher,
		private SchemaFetcher $schemaFetcher,
		private ImportedObjectWriter $writer,
		private FactoryDataBuilder $factoryData,
		private EventDispatcher $eventDispatcher,
		private BackupStore $syncBackup,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::JumpStartImporter);
	}

	/** @param array<int,array<string,mixed>> $objects */
	public function import(array $objects, ImportRun $run): void
	{
		// Track which objects landed where so one import.completed fires per
		// touched collection at the end. The writer suspends `object.created`
		// / `object.updated`, so IndexBuildListener never sees the per-object
		// events; without the completion signal the receiving end has the
		// objects on disk but a stale index — a sync push that appears to
		// succeed while the imported pages don't show up until a reindex.
		/** @var array<string,array{created:list<string>,updated:list<string>,count:int}> $touched */
		$touched = [];

		foreach ($objects as $objectDef) {
			$collectionId = $objectDef['collection'] ?? '';
			$objectId     = $objectDef['id'] ?? '';
			try {
				$action = $this->importObject($collectionId, $objectId, $objectDef['data'] ?? [], $run);
				if ($collectionId !== '' && $action !== 'skipped') {
					$touched[$collectionId] ??= ['created' => [], 'updated' => [], 'count' => 0];
					$touched[$collectionId]['count']++;
					$touched[$collectionId][$action][] = (string)$objectId;
				}
			} catch (\Exception $e) {
				$run->report->error(sprintf('Object %s/%s: %s', $collectionId, $objectId, $e->getMessage()));
			}
		}

		foreach ($touched as $collectionId => $stats) {
			$this->eventDispatcher->dispatch(
				CoreEvent::IMPORT_COMPLETED,
				new ImportEventPayload($collectionId, $stats['count'], $stats['created'], $stats['updated']),
			);
		}
	}

	/**
	 * @param array<string,mixed> $objectData
	 *
	 * @return 'created'|'updated'|'skipped'
	 */
	private function importObject(string $collectionId, string $objectId, array $objectData, ImportRun $run): string
	{
		if ($run->refuseSystemCollection($collectionId, 'Object')) {
			return 'skipped';
		}

		$collection = $this->collectionFetcher->fetchCollection($collectionId);
		if (!$collection instanceof CollectionData) {
			throw new \Exception('Collection not found');
		}

		$exists     = $this->objectFetcher->existsObject($collectionId, $objectId);
		$objectData = $this->factoryData->forImport($collectionId, $objectId, $objectData);

		if ($exists) {
			if (!$run->upsert) {
				$run->report->result(ImportReport::OBJECTS, sprintf('Object %s/%s: already exists, skipping', $collectionId, $objectId));

				return 'skipped';
			}
			$this->syncBackup->backupObject($collectionId, $objectId);
			$objectData = $this->preserveUntravelledMedia($collectionId, $objectId, $collection->schema, $objectData);
			$this->writer->update($collectionId, $objectId, $objectData);
			$run->report->result(ImportReport::OBJECTS, sprintf('Object %s/%s: updated', $collectionId, $objectId));

			return 'updated';
		}

		$this->writer->create($collectionId, $objectData);
		$run->report->result(ImportReport::OBJECTS, sprintf('Object %s/%s: created', $collectionId, $objectId));

		return 'created';
	}

	/**
	 * Carry the destination's own image/gallery values through an upsert.
	 *
	 * The upsert path replaces the whole object, so a field the payload does
	 * not mention would be wiped. JumpStart carries no binaries, which means
	 * an image field is not syncable data in either direction: the source
	 * cannot send one and must not clear one it never received. Only fields
	 * ABSENT from the payload are restored; a value that is present is an
	 * authored factory rule and is still honored.
	 *
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,mixed>
	 */
	private function preserveUntravelledMedia(string $collectionId, string $objectId, string $schemaId, array $objectData): array
	{
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
			if (!in_array($fieldType, ['image', 'gallery'], true) || array_key_exists($fieldName, $objectData)) {
				continue;
			}
			if (array_key_exists($fieldName, $existing)) {
				$objectData[$fieldName] = $existing[$fieldName];
			}
		}

		return $objectData;
	}
}
