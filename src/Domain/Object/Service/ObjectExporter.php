<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

final class ObjectExporter
{
	public function __construct(
		private IndexRepository $storage,
		private ObjectFetcher $objectFetcher,
	){}

	/** @return array<array<string,mixed>> */
	public function exportAllObjects(string $collection): array
	{
		$objects   = [];
		$objectIds = $this->storage->fetchObjectIds($collection);

		foreach ($objectIds as $id) {
			$object = $this->objectFetcher->fetchObject($collection, $id);
			$objects[] = $object->toArray();
		}

		return $objects;
	}
}
