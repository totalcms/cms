<?php

declare(strict_types=1);

namespace TotalCMS\Domain\DataView\Service;

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
		$items = $this->loadItems($viewId);

		return $this->pipeline->execute($items, $params, 'dataview-query:' . $viewId);
	}

	/**
	 * Load DataView items, auto-building if data doesn't exist.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function loadItems(string $viewId): array
	{
		if (!$this->dataViewFetcher->dataExists($viewId)) {
			$this->dataViewBuilder->buildView($viewId);
		}

		$data = $this->dataViewFetcher->getViewData($viewId);

		if ($data === []) {
			return [];
		}

		// Re-index to sequential keys (handles string-keyed arrays of objects)
		$items = array_values($data);

		// Ensure root-level array of objects
		if (!is_array($items[0])) {
			return [];
		}

		/** @var array<int,array<string,mixed>> */
		return $items;
	}
}
