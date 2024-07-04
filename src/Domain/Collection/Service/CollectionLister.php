<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Repository\CollectionRepository;

/**
 * Service.
 */
final class CollectionLister
{
	private CollectionRepository $storage;

	public function __construct(CollectionRepository $storage)
	{
		$this->storage = $storage;
	}

	/**
	 * List all collections.
	 *
	 * @return array<object>
	 */
	public function listAllCollections(): array
	{
		return $this->storage->listAllCollections();
	}
}
