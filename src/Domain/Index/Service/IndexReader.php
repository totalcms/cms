<?php

namespace TotalCMS\Domain\Index\Service;

use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;

/**
 * Service.
 */
readonly class IndexReader
{
	public function __construct(
		private IndexRepository $storage,
		private IndexBuilder $builder,
	) {
	}

	public function fetchIndex(string $collection): IndexData
	{
		$index = $this->storage->fetchIndex($collection);

		if (is_null($index)) {
			// Build the index if it does not exist
			$index = $this->builder->buildIndex($collection);
		}

		return $index;
	}
}
