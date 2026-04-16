<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Repository\CollectionRepository;

/**
 * Service.
 */
readonly class CollectionRemover
{
	public function __construct(
		private CollectionRepository $storage,
	) {
	}

	public function deleteCollection(string $collection): bool
	{
		return $this->storage->deleteCollection($collection);
	}
}
