<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Property\Data\DateData;

/**
 * Service.
 */
readonly class CollectionSaver
{
	public function __construct(
		private CollectionRepository $storage,
		private CollectionFactory $factory,
		private IndexRepository $indexRepository,
	) {
	}

	/**
	 * Save Collection data.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @throws \DomainException
	 * @throws \UnexpectedValueException
	 */
	public function saveCollection(array $data): CollectionData
	{
		$data['count'] = $this->initializeCount($data['id'], $data);

		// Clean lastUpdated to ensure proper ISO 8601 format
		if (!isset($data['lastUpdated']) || $data['lastUpdated'] === '') {
			$data['lastUpdated'] = DateData::cleanDate();
		} else {
			// Clean the provided date to ensure it's in the correct format
			$data['lastUpdated'] = DateData::cleanDate($data['lastUpdated']);
		}

		// Ensure formSettings is an array (handle empty strings from form)
		if (isset($data['formSettings']) && $data['formSettings'] === '') {
			$data['formSettings'] = [];
		}

		$collection = $this->factory->generateCollection($data);

		if ($this->storage->collectionExists($collection->id)) {
			throw new \DomainException(sprintf('Collection with id %s already exists', $collection->id));
		}

		$this->storage->saveCollection($collection);

		return $collection;
	}

	/**
	 * update Collection data.
	 *
	 * @param array<string,mixed> $data The collection data to save
	 *
	 * @throws \UnexpectedValueException
	 */
	public function updateCollection(string $collectionId, array $data): CollectionData
	{
		$data['count'] = $this->initializeCount($collectionId, $data);

		// Clean lastUpdated to ensure proper ISO 8601 format
		if (isset($data['lastUpdated']) && $data['lastUpdated'] !== '') {
			$data['lastUpdated'] = DateData::cleanDate($data['lastUpdated']);
		}

		// Ensure formSettings is an array (handle empty strings from form)
		if (isset($data['formSettings']) && $data['formSettings'] === '') {
			$data['formSettings'] = [];
		}

		$collection = $this->factory->generateCollection($data);

		if ($collection->id !== $collectionId) {
			throw new \UnexpectedValueException('Invalid Collection data provided. Does not match collection ID.', 1);
		}

		$this->storage->saveCollection($collection);

		return $collection;
	}

	/**
	 * update Collection data.
	 *
	 * @param array<string,mixed> $patch The collection data to patch
	 *
	 * @throws \UnexpectedValueException
	 */
	public function patchCollection(string $collectionId, array $patch): CollectionData
	{
		$collection = $this->storage->fetchCollection($collectionId);

		if (!$collection instanceof CollectionData) {
			throw new \UnexpectedValueException(sprintf('Error fetching Collection with id %s', $collectionId));
		}

		$mergedCollection = array_merge($collection->toArray(), $patch);

		return $this->updateCollection($collectionId, $mergedCollection);
	}

	/**
	 * Increment the object count for a collection.
	 *
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @throws \UnexpectedValueException
	 */
	public function incrementCount(string $collectionId): CollectionData
	{
		$collection = $this->storage->fetchCollection($collectionId);

		if (!$collection instanceof CollectionData) {
			throw new \UnexpectedValueException(sprintf('Error fetching Collection with id %s', $collectionId));
		}

		$collectionArray = $collection->toArray();

		// If count is not set or is 0, initialize it to current object count first
		if (!isset($collectionArray['count']) || $collectionArray['count'] === 0) {
			$objectIds                = $this->indexRepository->fetchObjectIds($collectionId);
			$collectionArray['count'] = count($objectIds);
		} else {
			$collectionArray['count']++;
		}

		return $this->updateCollection($collectionId, $collectionArray);
	}

	/**
	 * Increment totalObjects and update lastUpdated for a collection.
	 *
	 * @throws \UnexpectedValueException
	 */
	public function incrementTotalObjects(string $collectionId): CollectionData
	{
		$collection = $this->storage->fetchCollection($collectionId);

		if (!$collection instanceof CollectionData) {
			throw new \UnexpectedValueException(sprintf('Error fetching Collection with id %s', $collectionId));
		}

		$collectionArray                 = $collection->toArray();
		$collectionArray['totalObjects'] = ($collectionArray['totalObjects'] ?? 0) + 1;
		$collectionArray['lastUpdated']  = DateData::cleanDate();

		return $this->updateCollection($collectionId, $collectionArray);
	}

	/**
	 * Decrement totalObjects and update lastUpdated for a collection.
	 *
	 * @throws \UnexpectedValueException
	 */
	public function decrementTotalObjects(string $collectionId): CollectionData
	{
		$collection = $this->storage->fetchCollection($collectionId);

		if (!$collection instanceof CollectionData) {
			throw new \UnexpectedValueException(sprintf('Error fetching Collection with id %s', $collectionId));
		}

		$collectionArray                 = $collection->toArray();
		$collectionArray['totalObjects'] = max(0, ($collectionArray['totalObjects'] ?? 0) - 1);
		$collectionArray['lastUpdated']  = DateData::cleanDate();

		return $this->updateCollection($collectionId, $collectionArray);
	}

	/**
	 * Update lastUpdated timestamp for a collection (for updates/patches without count changes).
	 *
	 * @throws \UnexpectedValueException
	 */
	public function updateLastUpdated(string $collectionId): CollectionData
	{
		$collection = $this->storage->fetchCollection($collectionId);

		if (!$collection instanceof CollectionData) {
			throw new \UnexpectedValueException(sprintf('Error fetching Collection with id %s', $collectionId));
		}

		$collectionArray                = $collection->toArray();
		$collectionArray['lastUpdated'] = DateData::cleanDate();

		return $this->updateCollection($collectionId, $collectionArray);
	}

	/**	@param array<string,mixed> $data */
	private function initializeCount(string $collectionId, array $data): int
	{
		// Only initialize count if it's not set or is zero
		if (!isset($data['count']) || $data['count'] === 0) {
			$objectIds = $this->indexRepository->fetchObjectIds($collectionId);

			return count($objectIds);
		}

		return $data['count'];
	}
}
