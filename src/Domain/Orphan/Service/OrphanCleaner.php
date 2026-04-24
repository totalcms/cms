<?php

namespace TotalCMS\Domain\Orphan\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Orphan\Data\OrphanEntry;
use TotalCMS\Domain\Orphan\Data\OrphanReport;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\OperationResult;

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
	 */
	public function cleanProperty(
		string $collection,
		string $objectId,
		string $property,
		array $orphanedIds,
		bool $isArray,
	): OperationResult {
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

			return OperationResult::success();
		} catch (\Exception $e) {
			$this->logger->error('Failed to clean orphaned references', [
				'collection' => $collection,
				'objectId'   => $objectId,
				'property'   => $property,
				'error'      => $e->getMessage(),
			]);

			return OperationResult::failure($e->getMessage());
		}
	}

	/**
	 * Clean all entries in a report.
	 */
	public function cleanAll(OrphanReport $report): OperationResult
	{
		return $this->cleanEntries($report->getEntries());
	}

	/**
	 * Clean only entries for a specific collection.
	 */
	public function cleanByCollection(OrphanReport $report, string $collection): OperationResult
	{
		$entries = array_filter(
			$report->getEntries(),
			fn (OrphanEntry $e): bool => $e->collection === $collection
		);

		return $this->cleanEntries(array_values($entries));
	}

	/**
	 * Clean only entries for a specific collection + property.
	 */
	public function cleanByCollectionProperty(OrphanReport $report, string $collection, string $property): OperationResult
	{
		$entries = array_filter(
			$report->getEntries(),
			fn (OrphanEntry $e): bool => $e->collection === $collection && $e->property === $property
		);

		return $this->cleanEntries(array_values($entries));
	}

	/**
	 * @param array<OrphanEntry> $entries
	 */
	private function cleanEntries(array $entries): OperationResult
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

			if ($result->success) {
				$cleaned++;
			} else {
				$failed++;
				$errors[] = "{$entry->collection}/{$entry->objectId}.{$entry->property}: {$result->error}";
			}
		}

		return OperationResult::success('', [
			'cleaned' => $cleaned,
			'failed'  => $failed,
			'errors'  => $errors,
		]);
	}
}
