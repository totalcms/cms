<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for `bulk.deleted` — fired once at the end of a bulk delete with the
 * full set of object ids that were removed from the collection.
 *
 * Distinct from the per-object `object.deleted` (which still fires for each id
 * during the batch so search/MCP/dataview listeners stay correct): this batch
 * event lets listeners and event-trigger automations react to the bulk delete
 * as a single unit, mirroring how `import.completed` summarizes an import.
 */
readonly class BulkEventPayload extends EventPayload
{
	/**
	 * @param array<string> $ids IDs of the deleted objects
	 */
	public function __construct(
		public string $collection,
		public array $ids = [],
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'collection' => $this->collection,
			'ids'        => $this->ids,
			'count'      => count($this->ids),
		];
	}
}
