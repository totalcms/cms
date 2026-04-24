<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
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
		private CollectionFetcher $collectionFetcher,
		private EditionFeatureService $editionFeatures,
		private EventDispatcher $eventDispatcher,
	) {
	}

	/**
	 * Save Collection data.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @throws \DomainException
	 * @throws \UnexpectedValueException
	 * @throws \TotalCMS\Domain\License\Exception\EditionFeatureException
	 */
	public function saveCollection(array $data): CollectionData
	{
		// Check edition requirements for schema-specific features
		$this->validateSchemaEdition($data['schema'] ?? '');

		// Normalize URL to path only (strip domain if present)
		if (isset($data['url']) && $data['url'] !== '') {
			$data['url'] = CollectionData::normalizeUrlToPath($data['url']);
		}

		$data['count'] = $this->initializeCount($data['id'], $data);

		// Initialize totalObjects if not set
		if (!isset($data['totalObjects']) || $data['totalObjects'] === 0) {
			$objectIds            = $this->indexRepository->fetchObjectIds($data['id']);
			$data['totalObjects'] = count($objectIds);
		}

		// Preserve lastUpdated from existing collection if not provided
		if (!isset($data['lastUpdated']) || $data['lastUpdated'] === '') {
			$data['lastUpdated'] = 'now';
		}
		// Clean the provided lastUpdated to ensure proper ISO 8601 format
		$data['lastUpdated'] = DateData::cleanDate($data['lastUpdated']);

		// Ensure formSettings is an array (handle empty strings from form)
		if (isset($data['formSettings']) && $data['formSettings'] === '') {
			$data['formSettings'] = [];
		}

		// Ensure manualSort is an array (handle empty strings from form)
		if (isset($data['manualSort']) && $data['manualSort'] === '') {
			$data['manualSort'] = [];
		}

		$collection = $this->factory->generateCollection($data);

		if ($this->storage->collectionExists($collection->id)) {
			throw new \DomainException(sprintf('Collection with id %s already exists', $collection->id));
		}

		$this->storage->saveCollection($collection);

		// Clear request-level cache so subsequent fetches get fresh data
		$this->collectionFetcher->clearCache($collection->id);

		$this->eventDispatcher->dispatch('collection.created', [
			'collection' => $collection->id,
		]);

		return $collection;
	}

	/**
	 * update Collection data.
	 *
	 * @param array<string,mixed> $data The collection data to save
	 * @param CollectionData|null $existingCollection Optional existing collection to avoid double-fetching
	 *
	 * @throws \UnexpectedValueException
	 */
	public function updateCollection(string $collectionId, array $data, ?CollectionData $existingCollection = null): CollectionData
	{
		// Normalize URL to path only (strip domain if present)
		if (isset($data['url']) && $data['url'] !== '') {
			$data['url'] = CollectionData::normalizeUrlToPath($data['url']);
		}

		$data['count']       = $this->initializeCount($collectionId, $data);
		$data['lastUpdated'] = DateData::cleanDate();

		// Fetch existing collection to preserve system-managed fields if not provided
		if (!$existingCollection instanceof CollectionData) {
			$existingCollection = $this->storage->fetchCollection($collectionId);
		}

		if (!$existingCollection instanceof CollectionData) {
			throw new \UnexpectedValueException(sprintf('Error fetching Collection with id %s', $collectionId));
		}

		// Recalculate totalObjects from index if not explicitly provided (self-healing)
		if (!isset($data['totalObjects'])) {
			$objectIds            = $this->indexRepository->fetchObjectIds($collectionId);
			$data['totalObjects'] = count($objectIds);
		}

		// Ensure count >= totalObjects (count is lifetime, totalObjects is current)
		if ($data['count'] < $data['totalObjects']) {
			$data['count'] = $data['totalObjects'];
		}

		// Ensure formSettings is an array (handle empty strings from form)
		if (isset($data['formSettings']) && $data['formSettings'] === '') {
			$data['formSettings'] = [];
		}

		// Ensure manualSort is an array (handle empty strings from form)
		if (isset($data['manualSort']) && $data['manualSort'] === '') {
			$data['manualSort'] = [];
		}

		$collection = $this->factory->generateCollection($data);

		if ($collection->id !== $collectionId) {
			throw new \UnexpectedValueException('Invalid Collection data provided. Does not match collection ID.', 1);
		}

		$this->storage->saveCollection($collection);

		// Clear request-level cache so subsequent fetches get fresh data
		$this->collectionFetcher->clearCache($collectionId);

		$this->eventDispatcher->dispatch('collection.updated', [
			'collection' => $collectionId,
		]);

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
	 * @throws \UnexpectedValueException
	 */
	public function incrementCount(string $collectionId, int $incrementBy = 1): CollectionData
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
			$collectionArray['count'] += $incrementBy;
		}

		return $this->updateCollection($collectionId, $collectionArray);
	}

	/**
	 * Increment totalObjects for a collection.
	 *
	 * @throws \UnexpectedValueException
	 */
	public function incrementTotalObjects(string $collectionId, int $incrementBy = 1): CollectionData
	{
		$collection = $this->storage->fetchCollection($collectionId);

		if (!$collection instanceof CollectionData) {
			throw new \UnexpectedValueException(sprintf('Error fetching Collection with id %s', $collectionId));
		}

		$collectionArray                 = $collection->toArray();
		$collectionArray['totalObjects'] = ($collectionArray['totalObjects'] ?? 0) + $incrementBy;

		return $this->updateCollection($collectionId, $collectionArray, $collection);
	}

	/**
	 * Decrement totalObjects for a collection.
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

		return $this->updateCollection($collectionId, $collectionArray, $collection);
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

		$collectionArray = $collection->toArray();

		return $this->updateCollection($collectionId, $collectionArray, $collection);
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

	/**
	 * Validate that the schema is allowed for the current edition.
	 *
	 * @throws \TotalCMS\Domain\License\Exception\EditionFeatureException
	 */
	private function validateSchemaEdition(string $schema): void
	{
		// Map schemas to their required edition features
		$schemaToFeature = [
			'blog'        => EditionFeature::BLOG_SCHEMA,
			'blog-legacy' => EditionFeature::BLOG_SCHEMA,
			'depot'       => EditionFeature::DEPOT_SCHEMA,
		];

		// Check if this schema requires a specific edition feature
		if (isset($schemaToFeature[$schema])) {
			$this->editionFeatures->canOrFail($schemaToFeature[$schema]);
		}
	}
}
