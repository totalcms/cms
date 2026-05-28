<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Search\Service;

use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Data\SearchResult;

/**
 * Contract for the search service. The default implementation is SearchService.
 * Extracting the interface allows tests to stub search behavior without
 * instantiating the full provider stack.
 */
interface SearchServiceInterface
{
	/**
	 * @return list<SearchResult>
	 */
	public function search(SearchQuery $query): array;
}
