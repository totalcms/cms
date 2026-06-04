<?php

declare(strict_types=1);

namespace TotalCMS\Domain\DataView\Service;

use TotalCMS\Domain\JobQueue\Service\JobQueuer;

readonly class DataViewUpdateScheduler
{
	public function __construct(
		private DataViewDependencyResolver $resolver,
		private JobQueuer $jobQueuer,
	) {
	}

	/**
	 * Queue rebuilds for every DataView affected by a change to $collection,
	 * in dependency order (producers before consumers).
	 */
	public function scheduleUpdatesForCollection(string $collection): void
	{
		$viewIds = $this->resolver->resolveForCollection($collection);

		if ($viewIds === []) {
			return;
		}

		$this->jobQueuer->queueViewUpdates($viewIds);
	}
}
