<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for collection.created, collection.updated, and collection.deleted events.
 *
 * `configChanged` says whether the collection's configuration (schema, name,
 * url, mcp access, …) changed, as opposed to only its computed metadata —
 * `count`, `totalObjects`, `lastUpdated` — which every object write and every
 * index build bump through CollectionSaver::updateCollection(). Listeners
 * that react to the collection's *settings* (the MCP tool surface, sync
 * freshness) can ignore metadata-only updates; cache listeners keep treating
 * every update as a change. Created/deleted events always carry true.
 */
readonly class CollectionEventPayload extends EventPayload
{
	public function __construct(
		public string $collection,
		public bool $configChanged = true,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'collection'    => $this->collection,
			'configChanged' => $this->configChanged,
		];
	}
}
