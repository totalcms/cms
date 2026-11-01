<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Index\Service;

use TotalCMS\Domain\Auth\Data\ReadScope;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Query\Service\QueryPipeline;

/**
 * Orchestrates paginated index queries.
 *
 * Handles filtering, searching, sorting, and pagination of collection
 * index objects. Results are cached when not searching.
 */
readonly class IndexQueryService
{
	public function __construct(
		private IndexFilter $indexFilter,
		private QueryPipeline $pipeline,
		private CacheManager $cacheManager,
	) {
	}

	/**
	 * Query a collection index with filtering, sorting, and pagination.
	 *
	 * $scope, when given, is applied to the index BEFORE the pipeline runs,
	 * by exact string equality on the owner property ({@see ReadScope}), not
	 * the `include` filter grammar. The pipeline gives `ids` precedence over
	 * `filter`, `search` and the caller's own include/exclude, so a scope
	 * passed as a parameter could be widened by `?ids=`; applied here, nothing
	 * downstream can see an item the scope removed. CollectionAccessMiddleware
	 * and the MCP query tool use it to limit a non-admin to the objects they
	 * may read.
	 *
	 * @param string               $collection Collection identifier
	 * @param array<string,string> $params     Query parameters (limit, offset, sort, include, exclude, search, filter, ids)
	 * @param ReadScope|null       $scope      The actor's read scope, or null for an unscoped query
	 */
	public function query(string $collection, array $params, ?ReadScope $scope = null): QueryResult
	{
		// An all-access scope filters nothing; treat it as no scope.
		$scope = $scope instanceof ReadScope && !$scope->isAll() ? $scope : null;

		// Loaded only on a cache miss: at 50k objects the index decodes to
		// over 100 MB, which a cached page must not pay for.
		$load = function () use ($collection, $scope): array {
			$items = $this->indexFilter->fetchFilteredIndex($collection, []);

			return $scope !== null ? $scope->filter($items) : $items;
		};

		// The pipeline caches by prefix + params. The collection's query
		// version retires the entries when it changes. A scoped result must
		// not be served to an unscoped caller (or to a differently scoped
		// one), so the scope is part of the prefix.
		$cachePrefix = 'collection-query:' . $collection . '@' . $this->cacheManager->queryVersion('collection:' . $collection);
		if ($scope !== null) {
			$cachePrefix .= ':' . $scope->cacheKey();
		}

		return $this->pipeline->execute($load, $params, $cachePrefix);
	}
}
