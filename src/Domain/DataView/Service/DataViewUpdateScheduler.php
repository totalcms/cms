<?php

declare(strict_types=1);

namespace TotalCMS\Domain\DataView\Service;

use TotalCMS\Domain\DataView\Data\DataViewData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;

readonly class DataViewUpdateScheduler
{
	public function __construct(
		private IndexReader $indexReader,
		private JobQueuer $jobQueuer,
	) {
	}

	public function scheduleUpdatesForCollection(string $collection): void
	{
		try {
			$index = $this->indexReader->fetchIndex(DataViewData::COLLECTION_ID);
		} catch (\Throwable) {
			return; // Collection may not exist yet
		}

		foreach ($index->objects->toArray() as $view) {
			if (!is_array($view)) {
				continue;
			}

			$dependencies = $view['dependencies'] ?? [];
			if (!is_array($dependencies)) {
				continue;
			}

			if (in_array($collection, $dependencies, true)) {
				$viewId = (string) ($view['id'] ?? '');
				if ($viewId !== '') {
					$this->jobQueuer->queueViewUpdate($viewId);
				}
			}
		}
	}
}
