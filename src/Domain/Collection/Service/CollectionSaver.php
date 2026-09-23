<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\CollectionEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Exception\EditionFeatureException;
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
	 * @throws EditionFeatureException
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

		$data           = $this->normalizeSubmittedData($data);
		$data['format'] = $this->normalizeFormat($data['format'] ?? CollectionData::FORMAT_JSON);

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
		$data          = $this->normalizeSubmittedData($data);
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

		// The format is fixed at creation: the setting and the files on disk
		// must agree, and only CollectionFormatConverter changes both.
		// Normalized first so a harmless case/whitespace difference (e.g. the
		// form re-posting `JSON`) is not mistaken for an attempted change.
		if (isset($data['format']) && $data['format'] !== '' && $this->normalizeFormat($data['format']) !== $existingCollection->format) {
			throw new \DomainException(sprintf(
				"Collection '%s' is stored as %s. Change the storage format with `tcms collection:convert %s --to=%s`.",
				$collectionId,
				$existingCollection->format,
				$collectionId,
				(string)$data['format'],
			));
		}
		$data['format'] = $existingCollection->format;

		// Recalculate totalObjects from index if not explicitly provided (self-healing)
		if (!isset($data['totalObjects'])) {
			$objectIds            = $this->indexRepository->fetchObjectIds($collectionId);
			$data['totalObjects'] = count($objectIds);
		}

		// Ensure count >= totalObjects (count is lifetime, totalObjects is current)
		if ($data['count'] < $data['totalObjects']) {
			$data['count'] = $data['totalObjects'];
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
		$configChanged = $this->configSubset($collection) !== $this->configSubset($existingCollection);
		if ($preserveDates && $collection->updated !== '') {
			// keep the authored value verbatim
		} elseif ($configChanged) {
			$collection->updated = DateData::cleanDate();
		} else {
			$collection->updated = $existingCollection->updated;
		}

		$this->storage->saveCollection($collection);

		// Clear request-level cache so subsequent fetches get fresh data
		$this->collectionFetcher->clearCache($collectionId);

		// The payload says whether this was a real settings change or only the
		// metadata cascade (count / totalObjects / lastUpdated) that every
		// object write and index build routes through here — listeners that
		// care about the collection's configuration (MCP tool surface) skip
		// the latter, otherwise every content save would drop every MCP session.
		$this->eventDispatcher->dispatch(CoreEvent::COLLECTION_UPDATED, new CollectionEventPayload($collectionId, $configChanged));

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
	 * Merge a partial set of fields into the stored record.
	 *
	 * @param array<string,mixed> $patch
	 */
	public function patchCollection(string $collectionId, array $patch): CollectionData
	{
		return $this->mutateMetadata($collectionId, static function (array &$data) use ($patch): void {
			$data = array_merge($data, $patch);
		});
	}

	/**
	 * Bump the lifetime object counter (the OID counter). An unset or zero
	 * count is seeded from the objects on disk instead.
	 */
	public function incrementCount(string $collectionId, int $incrementBy = 1): CollectionData
	{
		return $this->mutateMetadata($collectionId, function (array &$data) use ($collectionId, $incrementBy): void {
			$data['count'] = $this->bumpedCount($collectionId, $data, $incrementBy);
		});
	}

	/**
	 * Record newly created objects: bump the lifetime count and the current
	 * totalObjects in ONE write. Object creation used to call incrementCount()
	 * then incrementTotalObjects(), writing and dispatching twice.
	 */
	public function incrementObjectCounts(string $collectionId, int $incrementBy = 1): CollectionData
	{
		return $this->mutateMetadata($collectionId, function (array &$data) use ($collectionId, $incrementBy): void {
			$data['count']        = $this->bumpedCount($collectionId, $data, $incrementBy);
			$data['totalObjects'] = ($data['totalObjects'] ?? 0) + $incrementBy;
		});
	}

	public function incrementTotalObjects(string $collectionId, int $incrementBy = 1): CollectionData
	{
		return $this->mutateMetadata($collectionId, static function (array &$data) use ($incrementBy): void {
			$data['totalObjects'] = ($data['totalObjects'] ?? 0) + $incrementBy;
		});
	}

	public function decrementTotalObjects(string $collectionId): CollectionData
	{
		return $this->mutateMetadata($collectionId, static function (array &$data): void {
			$data['totalObjects'] = max(0, ($data['totalObjects'] ?? 0) - 1);
		});
	}

	/**
	 * Restamp the content timestamp; updateCollection() does that on every
	 * write, so there is nothing to change in the record itself.
	 */
	public function updateLastUpdated(string $collectionId): CollectionData
	{
		return $this->mutateMetadata($collectionId, static function (array &$data): void {
		});
	}

	/**
	 * The one read-modify-write every metadata helper is: fetch the record,
	 * let $mutate change the array, save it through updateCollection() so
	 * the self-healing, timestamps and the collection.updated event apply.
	 *
	 * @param callable(array<string,mixed>&): void $mutate
	 */
	private function mutateMetadata(string $collectionId, callable $mutate): CollectionData
	{
		$collection = $this->storage->fetchCollection($collectionId);

		if (!$collection instanceof CollectionData) {
			throw new \UnexpectedValueException(sprintf('Error fetching Collection with id %s', $collectionId));
		}

		$data = $collection->toArray();
		$mutate($data);

		return $this->updateCollection($collectionId, $data, $collection);
	}

	/**
	 * The lifetime count after adding $incrementBy — unless the stored count
	 * is unset or zero, in which case it is seeded from the objects on disk
	 * (which already include the new ones).
	 *
	 * @param array<string,mixed> $data
	 */
	private function bumpedCount(string $collectionId, array $data, int $incrementBy): int
	{
		if (!isset($data['count']) || $data['count'] === 0) {
			return count($this->indexRepository->fetchObjectIds($collectionId));
		}

		return $data['count'] + $incrementBy;
	}

	/**
	 * Coerce what a form or a sync sends into the on-disk shape: the URL as a
	 * path, empty-string `formSettings` / `manualSort` as arrays, and an
	 * empty `mcp.tools` as an array. An entirely empty `mcp` block stays
	 * empty: `tcms push` sends "no MCP settings" as `mcp: []`, and growing
	 * it into `{tools: []}` made every sync dry run report a difference.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function normalizeSubmittedData(array $data): array
	{
		if (isset($data['url']) && $data['url'] !== '') {
			$data['url'] = CollectionData::normalizeUrlToPath($data['url']);
		}

		foreach (['formSettings', 'manualSort'] as $key) {
			if (isset($data[$key]) && $data[$key] === '') {
				$data[$key] = [];
			}
		}

		if (isset($data['mcp']) && is_array($data['mcp']) && $data['mcp'] !== []) {
			$tools = $data['mcp']['tools'] ?? null;
			if ($tools === null || $tools === '' || (is_string($tools) && trim($tools) === '')) {
				$data['mcp']['tools'] = [];
			}
		}

		return $data;
	}

	/**
	 * The stored count, or — when unset or zero — the number of objects on disk.
	 *
	 * @param array<string,mixed> $data
	 */
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
	 * @throws EditionFeatureException
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

	private function normalizeFormat(mixed $format): string
	{
		$format = strtolower(trim((string)$format));
		if ($format === '') {
			return CollectionData::FORMAT_JSON;
		}
		if (!in_array($format, CollectionData::FORMATS, true)) {
			throw new \DomainException(sprintf("Unknown storage format '%s'. Use one of: %s.", $format, implode(', ', CollectionData::FORMATS)));
		}

		return $format;
	}
}
