<?php

namespace TotalCMS\Domain\Index\Service;

final class IndexSearcher
{
	public function __construct(
		private IndexReader $reader,
	) {}

	/** @return array<array<string,mixed>> */
	public function searchByProperty(string $collection, string $property, string $query): array
	{
		$index = $this->reader->fetchIndex($collection);

		if (is_null($index)) {
			return [];
		}

		$objects = $index->objects->filter(function ($object) use ($property, $query) {
			return str_contains($object[$property], $query);
		});

		return $objects->toArray();
	}
}
