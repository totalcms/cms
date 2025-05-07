<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;

final class CollectionLister
{
	private CollectionRepository $storage;

	public function __construct(CollectionRepository $storage)
	{
		$this->storage = $storage;
	}

	/** @return array<CollectionData> */
	public function listAllCollections(): array
	{
		return $this->storage->listAllCollections();
	}
}
