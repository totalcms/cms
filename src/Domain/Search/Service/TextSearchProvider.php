<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Search\Service;

use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Query\Service\ObjectSearcher;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Data\SearchResult;

/**
 * Built-in default search provider. Implements the same filter+search
 * pipeline the MCP search tools previously did inline:
 *
 *   1. Load the filtered index for the collection via IndexFilter,
 *      applying persona-based filters (e.g., public persona excludes
 *      `draft:true`).
 *   2. Run in-memory full-text matching via ObjectSearcher.
 *   3. Score normalized 0-1 by inverse rank.
 *
 * `index()` and `delete()` are no-ops — T3's existing IndexBuilder
 * pipeline already maintains the IndexData files via object events.
 *
 * `isAvailable()` always returns true — local file I/O has no remote
 * failure surface.
 *
 * Cross-collection queries (collection=null) return [] — `SearchCollectionsTool`
 * iterates collections and calls TextSearchProvider once per collection.
 */
final readonly class TextSearchProvider implements SearchProvider
{
	public function __construct(
		private IndexFilter $filter,
		private ObjectSearcher $searcher,
	) {
	}

	public function id(): string
	{
		return 'text';
	}

	public function label(): string
	{
		return 'Text Search (built-in)';
	}

	public function search(SearchQuery $query): array
	{
		if ($query->collection === null) {
			return [];
		}

		// Persona-based pre-filter. Matches the behavior MCP search tools
		// used to apply inline before delegating to ObjectSearcher.
		$filterOptions = $query->persona === 'public' ? ['exclude' => 'draft:true'] : [];
		$items         = $this->filter->fetchFilteredIndex($query->collection, $filterOptions);

		// Opt-in relevance ranking: best-partial matches first (OR recall),
		// instead of the all-or-nothing AND filter below. $query->collection is
		// already non-null here (guarded above) — pass it narrowed.
		if ($query->relevance) {
			return $this->rankByRelevance($query, $query->collection, $items);
		}

		$matches = $this->searcher->search($items, $query->text);

		// Apply offset + limit window.
		$windowed = array_slice($matches, $query->offset, $query->limit);

		// Score = inverse rank rescaled to 0.5-1.0. First result gets 1.0;
		// last gets ~0.5. Cross-provider scores need a consistent range
		// for SearchService's fallback to make sense.
		$count   = count($windowed);
		$results = [];
		foreach ($windowed as $i => $object) {
			$id = (string)($object['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$score     = $count > 1 ? 1.0 - ($i / max($count - 1, 1)) * 0.5 : 1.0;
			$results[] = new SearchResult(
				collection: $query->collection,
				id: $id,
				score: $score,
			);
		}

		return $results;
	}

	/**
	 * Relevance-scored variant: rank by term coverage (OR recall, AND-biased),
	 * normalizing the best score to 1.0 so SearchResult stays in the 0-1 range
	 * SearchService expects. ObjectSearcher::searchScored() sorts before this
	 * windows, so the offset/limit slice operates on the already-ranked list.
	 *
	 * @param array<int,array<string,mixed>> $items
	 *
	 * @return list<SearchResult>
	 */
	private function rankByRelevance(SearchQuery $query, string $collection, array $items): array
	{
		$scored = $this->searcher->searchScored($items, $query->text, $query->weights);
		if ($scored === []) {
			return [];
		}

		$top      = $scored[0]['score'] > 0 ? $scored[0]['score'] : 1.0;
		$windowed = array_slice($scored, $query->offset, $query->limit);

		$results = [];
		foreach ($windowed as $entry) {
			$id = (string)($entry['item']['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$results[] = new SearchResult(
				collection: $collection,
				id: $id,
				score: $entry['score'] / $top,
			);
		}

		return $results;
	}

	public function index(string $collection, string $id, array $data): void
	{
		// No-op: IndexBuilder maintains IndexData on object events.
	}

	public function delete(string $collection, string $id): void
	{
		// No-op: IndexBuilder removes from IndexData on object.deleted.
	}

	public function isAvailable(): bool
	{
		return true;
	}
}
