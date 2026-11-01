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
 * - filter: list of filterCollection rules
 * - sort:   'date:desc,title:asc' or a list of sortCollection rules
 * - limit:  page size; omitted or 0 returns every match
 * - page:   1-based page number (needs a limit), or
 * - offset: items to skip
 */
readonly class TemplateQuery
{
	public function __construct(
		private IndexReader $indexReader,
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
		$filter = is_array($options['filter'] ?? null) ? array_values(array_filter($options['filter'], is_array(...))) : [];
		$sort   = $options['sort'] ?? '';
		$limit  = max(0, (int)($options['limit'] ?? 0));
		$offset = isset($options['page']) && $limit > 0
			? (max(1, (int)$options['page']) - 1) * $limit
			: max(0, (int)($options['offset'] ?? 0));

		// A clock operator's answer changes without a save; keep it a minute.
		if (CollectionRefiner::usesClock($filter)) {
			$key .= ':t' . date('YmdHi');
		}

		$params = ['filter' => $filter, 'sort' => $sort, 'limit' => $limit, 'offset' => $offset];
		$cached = $this->cacheManager->getApiResponse($key, $params);
		if ($cached instanceof QueryResult) {
			return $cached;
		}

		$items = $load();
		if ($filter !== []) {
			$refiner = new CollectionRefiner($items);
			$items   = $refiner->filterUnique($refiner->filter($filter));
		}
		$items = $this->sort($items, $sort);

		$total  = count($items);
		$result = new QueryResult(
			array_slice($items, $offset, $limit > 0 ? $limit : null),
			$total,
			$limit > 0 ? $limit : $total,
			$offset,
		);
		$this->cacheManager->storeApiResponse($key, $params, $result);

		return $result;
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function sort(array $items, mixed $sort): array
	{
		$rules = is_string($sort) ? SortRuleParser::parse($sort) : (is_array($sort) ? array_values(array_filter($sort, is_array(...))) : []);

		return $rules === [] ? $items : array_values((new CollectionSorter($items))->sortByRules($rules));
	}
}
