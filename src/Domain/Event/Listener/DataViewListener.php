<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Listener;

use TotalCMS\Domain\DataView\Data\DataViewData;
use TotalCMS\Domain\DataView\Service\DataViewUpdateScheduler;
use TotalCMS\Domain\Event\Service\EventDispatcher;

readonly class DataViewListener
{
	public function __construct(
		private DataViewUpdateScheduler $viewUpdateScheduler,
	) {
	}

	public function register(EventDispatcher $dispatcher): void
	{
		$dispatcher->listen('object.created', $this->onObjectChanged(...), -100);
		$dispatcher->listen('object.updated', $this->onObjectChanged(...), -100);
		$dispatcher->listen('object.deleted', $this->onObjectChanged(...), -100);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectChanged(array $payload): void
	{
		$collection = (string)$payload['collection'];

		// DataViewBuilder writes lastBuilt/lastError back to the `dataviews`
		// collection after every build, firing object.updated on it. Those
		// bookkeeping writes must not schedule rebuilds — otherwise a view that
		// (mis)declared `dataviews` as a collection dependency could re-trigger
		// itself indefinitely. View→view ordering is resolved from content-
		// collection changes via viewDependencies, never from this self-write.
		// (The MCP per-view subscription listener handles that same event
		// separately and is unaffected.)
		if ($collection === DataViewData::COLLECTION_ID) {
			return;
		}

		$this->viewUpdateScheduler->scheduleUpdatesForCollection($collection);
	}
}
