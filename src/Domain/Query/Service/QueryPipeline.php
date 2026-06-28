<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Query\Service;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Utilities\CollectionSorter;
use TotalCMS\Domain\Collection\Utilities\SortRuleParser;
use TotalCMS\Domain\Query\Data\QueryResult;

/**
 * Shared query pipeline for paginated queries.
 *
 * Handles searching, filtering, sorting, pagination, and caching.
 * Used by both IndexQueryService and DataViewQueryService.
 */
readonly class QueryPipeline
{
	private const MAX_LIMIT     = 100;
	private const DEFAULT_LIMIT = 20;

	public function __construct(
		private ObjectFilter $objectFilter,
		private ObjectSearcher $objectSearcher,
		private CacheManager $cacheManager,
	) {
	}

	/**
	 * Execute a query pipeline on pre-loaded items.
	 *
	 * @param array<int,array<string,mixed>> $items       Pre-loaded items to query
	 * @param array<string,string>           $params      Query parameters (limit, offset, sort, include, exclude, search)
	 * @param string                         $cachePrefix Cache key prefix (e.g. "collection-query:blog" or "dataview-query:my-view")
	 */
	public function execute(array $items, array $params, string $cachePrefix): QueryResult
	{
		$limit  = $this->clampInt($params['limit'] ?? '', 1, self::MAX_LIMIT, self::DEFAULT_LIMIT);
		$offset = max(0, (int)($params['offset'] ?? 0));
		$sort   = $params['sort'] ?? '';
		$search = $params['search'] ?? '';
		$filter = $params['filter'] ?? '';
		$ids    = $params['ids'] ?? '';

		// Check cache (skip for search, filter, and explicit-id queries)
		$useCache = $search === '' && $filter === '' && $ids === '';
		if ($useCache) {
			$cached = $this->cacheManager->getApiResponse($cachePrefix, $params);
			if ($cached instanceof QueryResult) {
				return $cached;
			}
		}

		// Explicit id set (the table's "show selected" view) takes precedence
		// over filter/search — it renders exactly the chosen objects.
		if ($ids !== '') {
			$items = $this->idsFilter($items, $ids);
		} elseif ($filter !== '') {
			$items = $this->containsFilter($items, $filter);
		} elseif ($search !== '') {
			$items = $this->objectSearcher->search($items, $search);
		} else {
			$filterOptions = $this->objectFilter->extractFilterOptions($params);
			if ($filterOptions !== []) {
				$items = $this->objectFilter->filterObjects($items, $filterOptions);
			}
		}

		// Sort
		if ($sort !== '') {
			$rules = SortRuleParser::parse($sort);
			if ($rules !== []) {
				$items = (new CollectionSorter($items))->sortByRules($rules);
			}
		}

		// Paginate
		$total  = count($items);
		$sliced = array_slice($items, $offset, $limit);
		$result = new QueryResult($sliced, $total, $limit, $offset);

		// Cache
		if ($useCache) {
			$this->cacheManager->storeApiResponse($cachePrefix, $params, $result);
		}

		return $result;
	}

	/**
	 * Restrict items to an explicit set of ids (comma-separated). Used by the
	 * collection table's "show selected" view to render only the rows the
	 * operator ticked, regardless of the active filter.
	 *
	 * @param array<int,array<string,mixed>> $items
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function idsFilter(array $items, string $ids): array
	{
		$wanted = array_filter(array_map(trim(...), explode(',', $ids)), static fn (string $id): bool => $id !== '');
		if ($wanted === []) {
			return [];
		}

		$lookup = array_flip($wanted);

		return array_values(array_filter(
			$items,
			static fn (array $item): bool => isset($item['id']) && isset($lookup[(string)$item['id']]),
		));
	}

	/**
	 * Simple contains filter — matches if any scalar field contains the term (case-insensitive).
	 *
	 * @param array<int,array<string,mixed>> $items
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function containsFilter(array $items, string $term): array
	{
		$term = mb_strtolower(trim($term));
		if ($term === '') {
			return $items;
		}

		return array_values(array_filter($items, static function (array $item) use ($term): bool {
			foreach ($item as $value) {
				if (is_scalar($value) && str_contains(mb_strtolower((string)$value), $term)) {
					return true;
				}
			}

			return false;
		}));
	}

	/**
	 * Clamp an integer value within bounds with a default.
	 */
	private function clampInt(string $value, int $min, int $max, int $default): int
	{
		if ($value === '') {
			return $default;
		}

		$int = (int)$value;

		return max($min, min($max, $int));
	}
}
