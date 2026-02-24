<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Index\Service;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Utilities\CollectionSorter;
use TotalCMS\Domain\Collection\Utilities\SortRuleParser;
use TotalCMS\Domain\Index\Data\QueryResult;

/**
 * Orchestrates paginated index queries.
 *
 * Handles filtering, searching, sorting, and pagination of collection
 * index objects. Results are cached when not searching.
 */
readonly class IndexQueryService
{
	private const MAX_LIMIT     = 100;
	private const DEFAULT_LIMIT = 20;

	public function __construct(
		private IndexFilter $indexFilter,
		private IndexSearcher $indexSearcher,
		private CacheManager $cacheManager,
	) {
	}

	/**
	 * Query a collection index with filtering, sorting, and pagination.
	 *
	 * @param string               $collection Collection identifier
	 * @param array<string,string> $params     Query parameters (limit, offset, sort, include, exclude, search)
	 */
	public function query(string $collection, array $params): QueryResult
	{
		$limit  = $this->clampInt($params['limit'] ?? '', 1, self::MAX_LIMIT, self::DEFAULT_LIMIT);
		$offset = max(0, (int)($params['offset'] ?? 0));
		$sort   = $params['sort'] ?? '';
		$search = $params['search'] ?? '';

		// Build cache key (skip cache for search queries)
		$useCache = $search === '';
		if ($useCache) {
			$cached = $this->cacheManager->getApiResponse('collection-query:' . $collection, $params);
			if ($cached instanceof QueryResult) {
				return $cached;
			}
		}

		// Fetch items: search or filter
		if ($search !== '') {
			$results = $this->indexSearcher->search($collection, $search);
			$items   = $results->values()->all();
		} else {
			$filterOptions = $this->indexFilter->extractFilterOptions($params);
			$items         = $filterOptions !== []
				? $this->indexFilter->fetchFilteredIndex($collection, $filterOptions)
				: $this->indexFilter->fetchFilteredIndex($collection);
		}

		// Sort if requested
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

		// Cache the result
		if ($useCache) {
			$this->cacheManager->storeApiResponse('collection-query:' . $collection, $params, $result);
		}

		return $result;
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
