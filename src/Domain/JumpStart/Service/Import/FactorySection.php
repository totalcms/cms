<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service\Import;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\JumpStart\Data\ImportReport;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * The `factory` section of a JumpStart definition: an entry with an `id`
 * generates one specific object, an entry with a `count` generates a batch.
 */
readonly class FactorySection
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private ObjectFetcher $objectFetcher,
		private FactoryImporter $factoryImporter,
		private FactoryDataBuilder $factoryData,
		private ImportedObjectWriter $writer,
	) {
	}

	/** @param array<int,array<string,mixed>> $items */
	public function import(array $items, ImportRun $run): void
	{
		foreach ($items as $factoryDef) {
			$collectionId = $factoryDef['collection'];
			$factoryData  = $factoryDef['data'] ?? [];
			$factoryId    = $factoryDef['id'] ?? '';

			if ($run->refuseSystemCollection((string)$collectionId, 'Factory')) {
				continue;
			}

			try {
				if (!empty($factoryId)) {
					$this->generateObject($collectionId, $factoryId, $factoryData, $run);
					continue;
				}
				$this->generateBatch($collectionId, $factoryDef['count'] ?? 1, $factoryData, $run);
			} catch (\Exception $e) {
				$run->report->error(sprintf('Factory %s/%s: %s', $collectionId, $factoryId, $e->getMessage()));
			}
		}
	}

	/** @param array<string,mixed> $factoryData */
	private function generateObject(string $collectionId, string $objectId, array $factoryData, ImportRun $run): void
	{
		$this->assertCollection($collectionId);

		if ($this->objectFetcher->existsObject($collectionId, $objectId)) {
			$run->report->result(ImportReport::FACTORY, sprintf('Factory %s/%s: already exists, skipping', $collectionId, $objectId));

			return;
		}

		$this->writer->create($collectionId, $this->factoryData->forFactory($collectionId, $objectId, $factoryData));
		$run->report->result(ImportReport::FACTORY, sprintf('Factory %s/%s: generated', $collectionId, $objectId));
	}

	/** @param array<string,mixed> $factoryData */
	private function generateBatch(string $collectionId, int $count, array $factoryData, ImportRun $run): void
	{
		$this->assertCollection($collectionId);

		$definitions = $this->factoryImporter->mergeFactoryDefinitions($collectionId, $factoryData);
		$imported    = $this->factoryImporter->import($collectionId, $count, $definitions);

		$run->report->result(ImportReport::FACTORY, sprintf('Factory %s: generated %d items', $collectionId, $imported), $imported);
	}

	private function assertCollection(string $collectionId): void
	{
		if (!$this->collectionFetcher->fetchCollection($collectionId) instanceof CollectionData) {
			throw new \Exception('Collection not found');
		}
	}
}
