<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

use TotalCMS\Domain\Object\Data\ObjectData;

/**
 * Payload for object.created, object.updated, and object.deleted events.
 *
 * `previous` carries the pre-save state on `object.updated`, so listeners can
 * diff old vs new without a second fetch. Null on `object.created` and when the
 * fetcher couldn't read the prior state (e.g. first-write or missing on disk).
 */
readonly class ObjectEventPayload extends EventPayload
{
	public function __construct(
		public string $collection,
		public string $id,
		public ?ObjectData $object = null,
		public ?ObjectData $previous = null,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		$data = [
			'collection' => $this->collection,
			'id'         => $this->id,
		];

		if ($this->object instanceof ObjectData) {
			$data['object'] = $this->object;
		}

		if ($this->previous instanceof ObjectData) {
			$data['previous'] = $this->previous;
		}

		return $data;
	}
}
