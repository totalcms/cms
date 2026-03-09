<?php

namespace TotalCMS\Domain\Orphan\Data;

readonly class OrphanEntry
{
	/**
	 * @param array<string> $orphanedIds
	 */
	public function __construct(
		public string $collection,
		public string $objectId,
		public string $property,
		public array $orphanedIds,
		public bool $isArray,
		public string $targetCollection,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'collection'       => $this->collection,
			'objectId'         => $this->objectId,
			'property'         => $this->property,
			'orphanedIds'      => $this->orphanedIds,
			'isArray'          => $this->isArray,
			'targetCollection' => $this->targetCollection,
		];
	}
}
