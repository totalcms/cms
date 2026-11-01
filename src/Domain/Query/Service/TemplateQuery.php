<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Query\Service;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Utilities\CollectionRefiner;
use TotalCMS\Domain\Collection\Utilities\CollectionSorter;
use TotalCMS\Domain\Collection\Utilities\SortRuleParser;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\DataView\Service\DataViewQueryService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Query\Data\QueryResult;

/**
 * cms.collection.query() and cms.view.query() in Twig: one page of a
 * collection or a Data View, filtered with filterCollection rules and sorted
 * with sortCollection rules or a sort string, so a template asks for exactly
 * what it shows.
 *
 * `objects() | filterCollection | paginate` decodes the whole index on every
 * request, over 100 MB at 50k objects. This caches the page under a query
 * version (CacheManager::queryVersion()): a collection's is retired when it
 * is saved, the Data Views' when any collection is.
 *
 * Options:
 * - search: free text, matched as cms.collection.search() does (every
 *           word, `or`, `field:value`); empty means no search, so a search
 *           page with no query lists everything
 * - searchPriority: a field name or list; a match in an earlier field
 *           ranks first when there is no sort
 * - filter: list of filterCollection rules
 * - sort:   'date:desc,title:asc' or a list of sortCollection rules
 * - limit:  page size; omitted or 0 returns every match
 * - page:   1-based page number (needs a limit), or
 * - offset: items to skip
 *
 * The search text is visitor input, so it is tidied (lowercase, single
 * spaces) and capped before it becomes part of the cache key, and only the
 * page is cached, never the whole match list.
 */
readonly class TemplateQuery
{
	/** Longest search text matched and cached; the rest is ignored. */
	public const SEARCH_MAX_LENGTH = 200;

	public function __construct(
		private IndexReader $indexReader,
		private IndexSearcher $searcher,
		private DataViewFetcher $dataViews,
		private CacheManager $cacheManager,
	) {
	}

	/** @param array<string,mixed> $options */
	public function collection(string $collection, array $options = []): QueryResult
	{
		$key = 'collection-template-query:' . $collection . '@' . $this->cacheManager->queryVersion('collection:' . $collection);

		return $this->page($key, $options, fn (): array => $this->indexReader->fetchIndex($collection)->objects->toArray());
	}

	/**
	 * A Data View's output must be a list of objects, as for its REST query;
	 * anything else gives an empty result. Like cms.view.get(), it reads the
	 * saved output and does not build an unbuilt view (the builder renders
	 * Twig, which would make Twig depend on itself).
	 *
	 * @param array<string,mixed> $options
	 */
	public function view(string $viewId, array $options = []): QueryResult
	{
		$key = 'view-template-query:' . $viewId . '@' . $this->cacheManager->queryVersion('dataviews');

		return $this->page($key, $options, fn (): array => DataViewQueryService::asItems($this->dataViews->getViewData($viewId)));
	}

	/**
	 * @param array<string,mixed>                          $options
	 * @param \Closure(): array<int,array<string,mixed>>  $load    runs only on a cache miss
	 */
	private function page(string $key, array $options, \Closure $load): QueryResult
	{
		$filter   = is_array($options['filter'] ?? null) ? array_values(array_filter($options['filter'], is_array(...))) : [];
		$sort     = $options['sort'] ?? '';
		$rules    = self::sortRules($sort);
		$search   = self::searchText($options['search'] ?? '');
		$priority = self::searchPriority($options['searchPriority'] ?? []);
		$limit    = max(0, (int)($options['limit'] ?? 0));
		$offset   = isset($options['page']) && $limit > 0
			? (max(1, (int)$options['page']) - 1) * $limit
			: max(0, (int)($options['offset'] ?? 0));

		// A clock operator's answer changes without a save; keep it a minute.
		if (CollectionRefiner::usesClock($filter)) {
			$key .= ':t' . date('YmdHi');
		}

		$match = ['filter' => $filter];
		if ($search !== '') {
			$match += ['search' => $search, 'searchPriority' => $priority];
		}

		// A shuffle must differ on every request, so only the matches are
		// cached; the order and the page are made fresh each time.
		if (self::shuffles($rules)) {
			$items = $this->cacheManager->getApiResponse($key . ':matches', $match);
			if (!is_array($items)) {
				$items = $this->match($load(), $search, $priority, $filter);
				$this->cacheManager->storeApiResponse($key . ':matches', $match, $items);
			}

			return self::result(array_values((new CollectionSorter($items))->sortByRules($rules)), $limit, $offset);
		}

		$params = $match + ['sort' => $sort, 'limit' => $limit, 'offset' => $offset];
		$cached = $this->cacheManager->getApiResponse($key, $params);
		if ($cached instanceof QueryResult) {
			return $cached;
		}

		$items = $this->match($load(), $search, $priority, $filter);
		if ($rules !== []) {
			$items = array_values((new CollectionSorter($items))->sortByRules($rules));
		}
		$result = self::result($items, $limit, $offset);
		$this->cacheManager->storeApiResponse($key, $params, $result);

		return $result;
	}

	/**
	 * The objects that match the search, then the filter, in index order (or
	 * search priority order).
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @param list<string>                   $priority
	 * @param list<array<mixed>>             $filter
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function match(array $items, string $search, array $priority, array $filter): array
	{
		if ($search !== '') {
			$items = $this->searcher->searchObjects(collect($items), $search, $priority)->values()->all();
		}
		if ($filter !== []) {
			$refiner = new CollectionRefiner($items);
			$items   = $refiner->filterUnique($refiner->filter($filter));
		}

		return $items;
	}

	/** @param array<int,array<string,mixed>> $items */
	private static function result(array $items, int $limit, int $offset): QueryResult
	{
		$total = count($items);

		return new QueryResult(
			array_slice($items, $offset, $limit > 0 ? $limit : null),
			$total,
			$limit > 0 ? $limit : $total,
			$offset,
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function sortRules(mixed $sort): array
	{
		if (is_string($sort)) {
			return SortRuleParser::parse($sort);
		}

		return is_array($sort) ? array_values(array_filter($sort, is_array(...))) : [];
	}

	/** @param array<int,array<string,mixed>> $rules */
	private static function shuffles(array $rules): bool
	{
		foreach ($rules as $rule) {
			if (!empty($rule['shuffle'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The search text as matched and cached: lowercase (matching ignores case
	 * anyway), single spaces, at most SEARCH_MAX_LENGTH characters, so
	 * "Pasta " and "pasta" share an entry and a long string cannot make a
	 * huge key.
	 */
	private static function searchText(mixed $search): string
	{
		if (!is_scalar($search)) {
			return '';
		}
		$text = trim((string)preg_replace('/\s+/u', ' ', mb_strtolower((string)$search)));

		return mb_substr($text, 0, self::SEARCH_MAX_LENGTH);
	}

	/** @return list<string> */
	private static function searchPriority(mixed $priority): array
	{
		$fields = is_string($priority) ? explode(',', $priority) : (is_array($priority) ? $priority : []);
		$fields = array_map(static fn (mixed $field): string => is_scalar($field) ? trim((string)$field) : '', $fields);

		return array_values(array_filter($fields, static fn (string $field): bool => $field !== ''));
	}
}
