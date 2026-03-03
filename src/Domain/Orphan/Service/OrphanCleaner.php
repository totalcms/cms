<?php

namespace TotalCMS\Domain\Orphan\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Orphan\Data\OrphanEntry;
use TotalCMS\Domain\Orphan\Data\OrphanReport;
use TotalCMS\Factory\LoggerFactory;

readonly class OrphanCleaner
{
	private LoggerInterface $logger;

	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('orphan-cleaner');
	}

	/**
	 * Clean a single property on a single object.
	 *
	 * @param array<string> $orphanedIds
	 *
	 * @return array{success:bool,error:string}
	 */
	public function cleanProperty(
		string $collection,
		string $objectId,
		string $property,
		array $orphanedIds,
		bool $isArray,
	): array {
		try {
			$object     = $this->objectFetcher->fetchObject($collection, $objectId);
			$objectData = $object->toArray();

			$currentValue = $objectData[$property] ?? null;

			if ($isArray && is_array($currentValue)) {
				$orphanSet = array_fill_keys($orphanedIds, true);
				$cleaned   = array_values(array_filter(
					$currentValue,
					fn (mixed $id): bool => !isset($orphanSet[(string)$id])
				));
				$objectData[$property] = $cleaned;
			} else {
				$objectData[$property] = null;
			}

			$this->objectUpdater->updateObject($collection, $objectId, $objectData);

			$this->logger->info('Cleaned orphaned references', [
				'collection'  => $collection,
				'objectId'    => $objectId,
				'property'    => $property,
				'orphanedIds' => $orphanedIds,
			]);

			return ['success' => true, 'error' => ''];
		} catch (\Exception $e) {
			$this->logger->error('Failed to clean orphaned references', [
				'collection' => $collection,
				'objectId'   => $objectId,
				'property'   => $property,
				'error'      => $e->getMessage(),
			]);

			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Clean all entries in a report.
	 *
	 * @return array{cleaned:int,failed:int,errors:array<string>}
	 */
	public function cleanAll(OrphanReport $report): array
	{
		return $this->cleanEntries($report->getEntries());
	}

	/**
	 * Clean only entries for a specific collection.
	 *
	 * @return array{cleaned:int,failed:int,errors:array<string>}
	 */
	public function cleanByCollection(OrphanReport $report, string $collection): array
	{
		$entries = array_filter(
			$report->getEntries(),
			fn (OrphanEntry $e): bool => $e->collection === $collection
		);

		return $this->cleanEntries(array_values($entries));
	}

	/**
	 * Clean only entries for a specific collection + property.
	 *
	 * @return array{cleaned:int,failed:int,errors:array<string>}
	 */
	public function cleanByCollectionProperty(OrphanReport $report, string $collection, string $property): array
	{
		$entries = array_filter(
			$report->getEntries(),
			fn (OrphanEntry $e): bool => $e->collection === $collection && $e->property === $property
		);

		return $this->cleanEntries(array_values($entries));
	}

	/**
	 * @param array<OrphanEntry> $entries
	 *
	 * @return array{cleaned:int,failed:int,errors:array<string>}
	 */
	private function cleanEntries(array $entries): array
	{
		$cleaned = 0;
		$failed  = 0;
		/** @var array<string> $errors */
		$errors = [];

		foreach ($entries as $entry) {
			$result = $this->cleanProperty(
				$entry->collection,
				$entry->objectId,
				$entry->property,
				$entry->orphanedIds,
				$entry->isArray,
			);

			if ($result['success']) {
				$cleaned++;
			} else {
				$failed++;
				$errors[] = "{$entry->collection}/{$entry->objectId}.{$entry->property}: {$result['error']}";
			}
		}

		return [
			'cleaned' => $cleaned,
			'failed'  => $failed,
			'errors'  => $errors,
		];
	}
}
