<?php

declare(strict_types=1);

namespace TotalCMS\Domain\DataView\Service;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Query\Service\QueryPipeline;

/**
 * Orchestrates paginated queries on DataView output.
 *
 * Handles filtering, searching, sorting, and pagination of DataView
 * data arrays. The DataView output must be a root-level array of objects.
 */
readonly class DataViewQueryService
{
	public function __construct(
		private DataViewFetcher $dataViewFetcher,
		private DataViewBuilder $dataViewBuilder,
		private QueryPipeline $pipeline,
		private CacheManager $cacheManager,
	) {
	}

	/**
	 * Query a DataView with filtering, sorting, searching, and pagination.
	 *
	 * @param string               $viewId DataView identifier
	 * @param array<string,string> $params Query parameters (limit, offset, sort, include, exclude, search)
	 */
	public function query(string $viewId, array $params): QueryResult
	{
		// Loaded only on a cache miss. Any collection save retires the
		// 'dataviews' version, since a view can draw on any collection.
		$prefix = 'dataview-query:' . $viewId . '@' . $this->cacheManager->queryVersion('dataviews');

		return $this->pipeline->execute(fn (): array => $this->items($viewId), $params, $prefix);
	}

	/**
	 * Load DataView items, auto-building if data doesn't exist.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function items(string $viewId): array
	{
		if (!$this->dataViewFetcher->dataExists($viewId)) {
			$this->dataViewBuilder->buildView($viewId);
		}

		return self::asItems($this->dataViewFetcher->getViewData($viewId));
	}

	/**
	 * A view's output as queryable items: a list of objects, re-indexed.
	 * Anything else (a stats object, a list of strings) is empty. Shared with
	 * cms.view.query() (TemplateQuery).
	 *
	 * @param array<mixed> $data
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function asItems(array $data): array
	{
		if ($data === []) {
			return [];
		}

		// Re-index to sequential keys (handles string-keyed arrays of objects)
		$items = array_values($data);

		// Ensure root-level array of objects
		if (!is_array($items[0])) {
			return [];
		}

		return $items;
	}
}
