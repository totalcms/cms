<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

use TotalCMS\Domain\Object\Data\ObjectData;

/**
 * Payload for object.created, object.updated, and object.deleted events.
 */
readonly class ObjectEventPayload extends EventPayload
{
	public function __construct(
		public string $collection,
		public string $id,
		public ?ObjectData $object = null,
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

		return $data;
	}
}
