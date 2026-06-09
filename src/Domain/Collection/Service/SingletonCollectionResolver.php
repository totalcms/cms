<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Service\ObjectCloner;
use TotalCMS\Domain\Object\Service\ObjectRemover;

/**
 * Singleton-collection behavior. A singleton collection holds exactly one
 * object whose id equals the collection id. Behavior is "active" only when the
 * live object count is <= 1; with more objects it stays a normal list
 * (dormant) so nothing is hidden or deleted.
 */
final readonly class SingletonCollectionResolver
{
	public function __construct(
		private IndexRepository $indexRepository,
		private ObjectCloner $objectCloner,
		private ObjectRemover $objectRemover,
	) {
	}

	/** The canonical object id for a singleton: the collection id itself. */
	public function objectId(CollectionData $collection): string
	{
		return $collection->id;
	}

	/** Active = flagged singleton AND at most one object exists. */
	public function isActive(CollectionData $collection): bool
	{
		return $collection->singleton
			&& count($this->indexRepository->fetchObjectIds($collection->id)) <= 1;
	}

	/**
	 * The object id to open for an active singleton, ensuring the one object
	 * sits at the collection id — re-keying a single mismatched-id object
	 * (clone + delete) when converting a pre-existing collection.
	 *
	 * Returns null when the collection is empty: the caller routes to the
	 * new-object form instead of creating an (invalid, required-fields) shell.
	 * ObjectSaver forces the first save's id to the collection id, so the
	 * object is born correct.
	 */
	public function resolveTarget(CollectionData $collection): ?string
	{
		$collectionId = $collection->id;
		$ids          = array_values($this->indexRepository->fetchObjectIds($collectionId));

		if ($ids === []) {
			return null;
		}

		if (count($ids) === 1 && $ids[0] !== $collectionId) {
			$this->objectCloner->cloneObject(
				['collection' => $collectionId, 'id' => $ids[0]],
				['collection' => $collectionId, 'id' => $collectionId],
			);
			$this->objectRemover->deleteObject($collectionId, $ids[0]);
		}

		return $collectionId;
	}
}
