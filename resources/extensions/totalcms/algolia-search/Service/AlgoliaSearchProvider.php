<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\AlgoliaSearch\Service;

use Algolia\AlgoliaSearch\Api\SearchClient;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Data\SearchResult;
use TotalCMS\Domain\Search\Service\SearchProvider;

/**
 * SearchProvider implementation backed by Algolia.
 *
 * Each T3 object is indexed as one Algolia record with `objectID` =
 * "{collection}/{id}". The collection is also stored as a faceted
 * attribute so per-collection queries filter efficiently.
 *
 * `isAvailable()` returns true when API keys are populated. A future
 * enhancement could probe Algolia's status endpoint and cache the result;
 * for v1 we trust the keys and let search/index failures trigger fallback
 * via SearchService's exception-handling path.
 */
final class AlgoliaSearchProvider implements SearchProvider
{
	private ?SearchClient $client = null;

	public function __construct(
		private readonly string $appId,
		private readonly string $adminApiKey,
		private readonly string $searchApiKey,
		private readonly string $indexName,
	) {
	}

	public function id(): string
	{
		return 'algolia';
	}

	public function label(): string
	{
		return 'Algolia';
	}

	public function search(SearchQuery $query): array
	{
		if (!$this->isAvailable()) {
			return [];
		}

		$searchParams = [
			'query'       => $query->text,
			'hitsPerPage' => $query->limit,
			'page'        => intdiv($query->offset, max($query->limit, 1)),
		];
		if ($query->collection !== null) {
			$searchParams['filters'] = sprintf('collection:%s', $this->escapeFilterValue($query->collection));
		}

		$response = $this->getClient()->searchSingleIndex($this->indexName, $searchParams);

		$hits    = is_array($response['hits'] ?? null) ? $response['hits'] : [];
		$total   = count($hits);
		$results = [];

		foreach (array_values($hits) as $i => $hit) {
			$objectId = (string)($hit['objectID'] ?? '');
			$parts    = explode('/', $objectId, 2);
			if (count($parts) !== 2) {
				continue;
			}
			[$collection, $id] = $parts;

			$results[] = new SearchResult(
				collection: $collection,
				id: $id,
				// Score by rank: first hit ~1.0, last hit ~0.5. Algolia returns
				// hits in relevance order; we don't trust the absolute scores
				// across queries because Algolia's _rankingInfo is multi-
				// dimensional. Linear rescale keeps cross-provider behaviour
				// consistent with TextSearchProvider's scoring.
				score: $total > 1 ? 1.0 - ($i / max($total - 1, 1)) * 0.5 : 1.0,
				snippet: $this->extractSnippet($hit['_snippetResult'] ?? null),
			);
		}

		return $results;
	}

	public function index(string $collection, string $id, array $data): void
	{
		if (!$this->isAvailable()) {
			return;
		}

		$record               = $data;
		$record['objectID']   = $collection . '/' . $id;
		$record['collection'] = $collection;

		$this->getClient()->saveObject($this->indexName, $record);
	}

	public function delete(string $collection, string $id): void
	{
		if (!$this->isAvailable()) {
			return;
		}

		$this->getClient()->deleteObject($this->indexName, $collection . '/' . $id);
	}

	public function isAvailable(): bool
	{
		return $this->appId !== ''
			&& $this->adminApiKey !== ''
			&& $this->searchApiKey !== '';
	}

	private function getClient(): SearchClient
	{
		if ($this->client === null) {
			// Use the admin key for write operations. A future enhancement
			// could keep two clients (admin + search-only) and route queries
			// to the search-only key — for now keep one client for simplicity.
			$this->client = SearchClient::create($this->appId, $this->adminApiKey);
		}

		return $this->client;
	}

	private function escapeFilterValue(string $value): string
	{
		return '"' . str_replace('"', '\\"', $value) . '"';
	}

	/**
	 * @param mixed $snippetResult
	 */
	private function extractSnippet($snippetResult): ?string
	{
		if (!is_array($snippetResult)) {
			return null;
		}
		// Pull whatever string snippet first appears in the array.
		foreach ($snippetResult as $val) {
			if (is_array($val) && isset($val['value']) && is_string($val['value'])) {
				return $val['value'];
			}
		}

		return null;
	}
}
