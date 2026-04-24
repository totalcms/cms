<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for import.completed events.
 */
readonly class ImportEventPayload extends EventPayload
{
	public function __construct(
		public string $collection,
		public int $count,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'collection' => $this->collection,
			'count'      => $this->count,
		];
	}
}
