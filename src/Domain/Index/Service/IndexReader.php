<?php

namespace TotalCMS\Domain\Index\Service;

use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;

/**
 * Service.
 */
final class IndexReader
{
	public function __construct(
		private IndexRepository $storage,
		private IndexBuilder $builder
	) {}

	public function fetchIndex(string $collection): ?IndexData
	{
		$index = $this->storage->fetchIndex($collection);

		if (is_null($index)) {
			// Build the index if it does not exist
			$this->builder->buildIndex($collection);
		}

		$index = $this->storage->fetchIndex($collection);

		if ($index instanceof IndexData) {
			// Build the index if it does not exist
			return $index;
		}

		return null;
	}
}
