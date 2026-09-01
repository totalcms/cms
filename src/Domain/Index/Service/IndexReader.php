<?php

declare(strict_types=1);

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
			$built = $this->builder->buildIndex($collection);

			// Read back what the build wrote rather than returning its value.
			// Above IndexBuilder's streaming threshold the build writes entries
			// straight to the index file and returns an EMPTY IndexData — the
			// content is never assembled in memory. Returning that verbatim
			// served an empty index for exactly the collections large enough
			// that nobody notices quickly, so every listing on the site came
			// back empty until some later request read the index again.
			return $this->storage->fetchIndex($collection) ?? $built;
		}

		return $index;
	}
}
