<?php

declare(strict_types=1);

namespace Tests\Fakes;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * An ObjectFetcher that calls $afterRead once a read from disk is done, so a
 * test can act in the middle of an index build: write, edit or delete files,
 * or check who holds a lock. The callback decides itself whether to act.
 */
final readonly class PausingObjectFetcher extends ObjectFetcher
{
	/** @param \Closure(string, string): void $afterRead called with the collection and the id just read */
	public function __construct(ObjectRepository $storage, private \Closure $afterRead)
	{
		parent::__construct($storage);
	}

	public function fetchObjectFromDisk(string $collection, string $id): ObjectData
	{
		$object = parent::fetchObjectFromDisk($collection, $id);
		($this->afterRead)($collection, $id);

		return $object;
	}
}
