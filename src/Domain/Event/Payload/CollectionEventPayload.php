<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for collection.created, collection.updated, and collection.deleted events.
 */
readonly class CollectionEventPayload extends EventPayload
{
	public function __construct(
		public string $collection,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'collection' => $this->collection,
		];
	}
}
