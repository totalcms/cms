<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\CollectionEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Schema\Data\SchemaData;

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
	/**
	 * @param array<string,mixed> $data
	 * @param bool                $preserveDates Keep an authored `updated` (settings)
	 *                                           timestamp instead of stamping now.
	 *                                           Used by imports — the incoming value
	 *                                           is the source's history. Same contract
	 *                                           as SchemaSaver::saveSchema().
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function saveCollection(array $data, bool $preserveDates = false): CollectionData
	{
		// Reference schemas (totalcms, totalcms-item) are examples only and can
		// never back a collection.
		$schema = (string)($data['schema'] ?? '');
		if (SchemaData::isReferenceSchema($schema)) {
			throw new \DomainException(
				"The \"{$schema}\" schema is a reference example and cannot be used to create a collection."
			);
		}

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

		// Settings timestamp: creation counts as a settings change. An import
		// carrying an authored value keeps it (the source's history).
		if (!$preserveDates || !isset($data['updated']) || $data['updated'] === '') {
			$data['updated'] = DateData::cleanDate();
		}

		// Ensure formSettings is an array (handle empty strings from form)
		if (isset($data['formSettings']) && $data['formSettings'] === '') {
			$data['formSettings'] = [];
		}

		// Ensure manualSort is an array (handle empty strings from form)
		if (isset($data['manualSort']) && $data['manualSort'] === '') {
			$data['manualSort'] = [];
		}

		// Ensure mcp.tools is an array — empty/missing/null submissions land as [].
		// Validation happens upstream in the Action layer via ValidatesMcpToolsTrait;
		// this normalises the canonical on-disk shape so reads never see a non-array.
		if (isset($data['mcp']) && is_array($data['mcp'])) {
			$tools = $data['mcp']['tools'] ?? null;
			if ($tools === null || $tools === '' || (is_string($tools) && trim($tools) === '')) {
				$data['mcp']['tools'] = [];
			}
		}

		$collection = $this->factory->generateCollection($data);

		if ($this->storage->collectionExists($collection->id)) {
			throw new \DomainException(sprintf('Collection with id %s already exists', $collection->id));
		}

		$this->storage->saveCollection($collection);

		// Clear request-level cache so subsequent fetches get fresh data
		$this->collectionFetcher->clearCache($collection->id);

		$this->eventDispatcher->dispatch(CoreEvent::COLLECTION_CREATED, new CollectionEventPayload($collection->id));

		return $collection;
	}

	/**
	 * update Collection data.
	 *
	 * @param array<string,mixed> $data The collection data to save
	 * @param CollectionData|null $existingCollection Optional existing collection to avoid double-fetching
	 * @param bool                $preserveDates Keep an authored `updated` (settings)
	 *                                           timestamp instead of delta-stamping.
	 *                                           Used by sync imports so a synced copy
	 *                                           never reads newer than its source.
	 *
	 * @throws \UnexpectedValueException
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function updateCollection(string $collectionId, array $data, ?CollectionData $existingCollection = null, bool $preserveDates = false): CollectionData
	{
		// Normalize URL to path only (strip domain if present)
		if (isset($data['url']) && $data['url'] !== '') {
			$data['url'] = CollectionData::normalizeUrlToPath($data['url']);
		}

		$data['count'] = $this->initializeCount($collectionId, $data);

		// lastUpdated (the CONTENT timestamp) restamps on every update — the
		// object-write cascade depends on that. The one exception is a sync
		// import (preserveDates): a settings push must not make the collection
		// look content-fresh, so the local value the importer overlaid is kept.
		if (!$preserveDates || !isset($data['lastUpdated']) || $data['lastUpdated'] === '') {
			$data['lastUpdated'] = DateData::cleanDate();
		} else {
			$data['lastUpdated'] = DateData::cleanDate((string)$data['lastUpdated']);
		}

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

		// Ensure mcp.tools is an array — empty/missing/null submissions land as [].
		// Validation happens upstream in the Action layer via ValidatesMcpToolsTrait;
		// this normalises the canonical on-disk shape so reads never see a non-array.
		if (isset($data['mcp']) && is_array($data['mcp'])) {
			$tools = $data['mcp']['tools'] ?? null;
			if ($tools === null || $tools === '' || (is_string($tools) && trim($tools) === '')) {
				$data['mcp']['tools'] = [];
			}
		}

		$collection = $this->factory->generateCollection($data);

		if ($collection->id !== $collectionId) {
			throw new \UnexpectedValueException('Invalid Collection data provided. Does not match collection ID.', 1);
		}

		// Settings timestamp (`updated`), delta-stamped: every object write
		// routes through this method via the metadata cascade (count /
		// lastUpdated / totalObjects bumps), so stamping unconditionally
		// would make it as unreliable as lastUpdated. Comparing the config
		// subset means it moves ONLY when settings actually changed — which
		// is what sync freshness hints rely on. Imports preserve an authored
		// value: it is the source's history, not a new edit here.
		if ($preserveDates && $collection->updated !== '') {
			// keep the authored value verbatim
		} elseif ($this->configSubset($collection) !== $this->configSubset($existingCollection)) {
			$collection->updated = DateData::cleanDate();
		} else {
			$collection->updated = $existingCollection->updated;
		}

		$this->storage->saveCollection($collection);

		// Clear request-level cache so subsequent fetches get fresh data
		$this->collectionFetcher->clearCache($collectionId);

		$this->eventDispatcher->dispatch(CoreEvent::COLLECTION_UPDATED, new CollectionEventPayload($collectionId));

		return $collection;
	}

	/**
	 * The configuration portion of a collection — everything except the
	 * environment-local computed fields and the timestamps themselves.
	 * Two collections with equal config subsets differ only in content
	 * activity, which must not move the settings timestamp.
	 *
	 * @return array<string,mixed>
	 */
	private function configSubset(CollectionData $collection): array
	{
		$config = $collection->toArray();
		unset($config['count'], $config['totalObjects'], $config['lastUpdated'], $config['updated']);

		return $config;
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
