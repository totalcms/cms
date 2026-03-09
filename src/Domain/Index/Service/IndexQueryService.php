<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Index\Service;

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
		$items = $this->indexFilter->fetchFilteredIndex($collection);

		return $this->pipeline->execute($items, $params, 'collection-query:' . $collection);
	}
}
