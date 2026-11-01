<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;

/**
 * Hands out ${oid} numbers. A create claims its number the moment its id
 * generation asks for one, under the collection's metadata lock, so two
 * creates that overlap can never get the same number. Claiming replaces the
 * old bump of `count` after the save, which left a gap where a second create
 * read the same count.
 *
 * A create that never asks (a submitted id, a schema without ${oid}) claims
 * nothing here, so a refused one leaves the collection untouched. Every
 * create that is written reports it through recordCreate(), which advances
 * the count for those.
 *
 * Everything else only peeks: generateObject() also runs when an object is
 * read or updated, and an empty autogen field must not use up a number each
 * time. Imports peek too; they advance the count in memory and write it once
 * at import.completed (see ObjectImporter).
 *
 * A claimed number is spent even if the create then fails (a validation
 * error), so numbers can have gaps. They never repeat.
 */
class OidAllocator
{
	/** A create is in progress but has not needed a number yet. Numbers start at 1. */
	private const PENDING = 0;

	/** @var array<string,int> collection id => the number claimed by the create in progress, or PENDING */
	private array $claimed = [];

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CollectionSaver $collectionSaver,
	) {
	}

	/**
	 * Run $create as a create in $collection: the first next() for that
	 * collection inside it claims a number, and every later one returns the
	 * same number.
	 *
	 * @template T
	 *
	 * @param callable(): T $create
	 * @param-out bool      $claimed whether $create used up a number
	 *
	 * @return T
	 */
	public function creating(string $collection, callable $create, ?bool &$claimed = null): mixed
	{
		$claimed                    = false;
		$outer                      = $this->claimed[$collection] ?? null;
		$this->claimed[$collection] = self::PENDING;

		try {
			$result  = $create();
			$claimed = $this->hasClaimed($collection);

			return $result;
		} finally {
			if ($outer === null) {
				unset($this->claimed[$collection]);
			} else {
				$this->claimed[$collection] = $outer;
			}
		}
	}

	/**
	 * Count an object that was just written. $claimed: its create already
	 * took a number (the out-param of creating()).
	 */
	public function recordCreate(string $collection, bool $claimed): void
	{
		$this->collectionSaver->recordCreated($collection, $claimed);
	}

	/** Whether the create in progress in $collection has taken a number. $create() can, so this is read after it runs. */
	private function hasClaimed(string $collection): bool
	{
		return ($this->claimed[$collection] ?? self::PENDING) !== self::PENDING;
	}

	/** The number of the create in progress, or a peek at the next one. */
	public function next(string $collection): int
	{
		if (isset($this->claimed[$collection])) {
			if ($this->claimed[$collection] === self::PENDING) {
				$this->claimed[$collection] = $this->collectionSaver->claimOid($collection);
			}

			return $this->claimed[$collection];
		}

		$collectionData = $this->collectionFetcher->fetchCollection($collection);

		return $collectionData instanceof CollectionData ? $collectionData->count + 1 : 1;
	}
}
