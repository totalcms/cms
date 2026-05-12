<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Listener;

use TotalCMS\Domain\DataView\Service\DataViewUpdateScheduler;
use TotalCMS\Domain\Event\EventDispatcher;

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

		$this->viewUpdateScheduler->scheduleUpdatesForCollection($collection);
	}
}
