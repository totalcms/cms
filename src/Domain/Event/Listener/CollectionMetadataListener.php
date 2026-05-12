<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Listener;

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\EventDispatcher;

readonly class CollectionMetadataListener
{
	public function __construct(
		private CollectionSaver $collectionSaver,
	) {
	}

	public function register(EventDispatcher $dispatcher): void
	{
		$dispatcher->listen('object.created', $this->onObjectCreated(...), -100);
		$dispatcher->listen('object.updated', $this->onObjectUpdated(...), -100);
		$dispatcher->listen('object.deleted', $this->onObjectDeleted(...), -100);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectCreated(array $payload): void
	{
		$collection = (string)$payload['collection'];

		$this->collectionSaver->incrementCount($collection);
		$this->collectionSaver->incrementTotalObjects($collection);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectUpdated(array $payload): void
	{
		$collection = (string)$payload['collection'];

		$this->collectionSaver->updateLastUpdated($collection);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectDeleted(array $payload): void
	{
		$collection = (string)$payload['collection'];

		$this->collectionSaver->decrementTotalObjects($collection);
	}
}
