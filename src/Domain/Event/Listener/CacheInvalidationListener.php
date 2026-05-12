<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Listener;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Event\EventDispatcher;

readonly class CacheInvalidationListener
{
	public function __construct(
		private CacheManager $cacheManager,
	) {
	}

	public function register(EventDispatcher $dispatcher): void
	{
		$dispatcher->listen('collection.created', $this->onCollectionChanged(...), -90);
		$dispatcher->listen('collection.updated', $this->onCollectionChanged(...), -90);
		$dispatcher->listen('collection.deleted', $this->onCollectionChanged(...), -90);
		$dispatcher->listen('import.completed', $this->onCollectionChanged(...), -90);
		$dispatcher->listen('schema.saved', $this->onSchemaSaved(...), -90);
	}

	/** @param array<string,mixed> $payload */
	public function onCollectionChanged(array $payload): void
	{
		$collection = (string)$payload['collection'];

		$this->cacheManager->clearCollectionIndex($collection);
	}

	/** @param array<string,mixed> $payload */
	public function onSchemaSaved(array $payload): void
	{
		$schemaId = (string)$payload['schema'];

		$this->cacheManager->clearComputedData("schema_flattened:{$schemaId}");
	}
}
