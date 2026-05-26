<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Search\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Support\Config;

/**
 * Public face of the search abstraction. MCP tools, future REST endpoints,
 * and the future site-wide search extension call `search()` and don't need
 * to know which provider runs underneath.
 *
 * Resolution order:
 *   1. Check collection.mcp.searchProvider for a per-collection override.
 *      - 'text'    → force text search (skip all provider logic).
 *      - <id>      → use that specific provider if registered; else fall through.
 *      - 'default' → fall through to site-wide config.
 *   2. Read `search.activeProvider` from Config — defaults to 'text'.
 *   3. If 'text' → route to TextSearchProvider directly.
 *   4. Otherwise look up the active provider in the registry.
 *   5. If missing OR isAvailable() returns false → fall back to text.
 *   6. Call provider->search(). On any exception → log + fall back to text.
 */
final readonly class SearchService implements SearchServiceInterface
{
	public function __construct(
		private SearchProviderRegistry $registry,
		private SearchProvider $fallback,
		private LoggerInterface $logger,
		private Config $config,
		private CollectionFetcher $collectionFetcher,
	) {
	}

	/**
	 * @return list<\TotalCMS\Domain\Search\Data\SearchResult>
	 */
	public function search(SearchQuery $query): array
	{
		$provider = $this->resolveProviderForQuery($query);

		if ($provider === null || !$provider->isAvailable()) {
			if ($provider !== null) {
				// Active provider exists but unavailable; log + fall back
				$this->logger->info('Active search provider unavailable; falling back to text search', [
					'requested' => $provider->id(),
				]);
			}
			return $this->fallback->search($query);
		}

		try {
			return $provider->search($query);
		} catch (\Throwable $e) {
			$this->logger->warning('Search provider threw; falling back to text search', [
				'provider' => $provider->id(),
				'error'    => $e->getMessage(),
				'query'    => $query->text,
			]);
			return $this->fallback->search($query);
		}
	}

	/**
	 * Resolve the right provider for THIS query, honouring per-collection
	 * overrides at collection.mcp.searchProvider:
	 *   - 'text'    → return null (caller falls back to text)
	 *   - <id>      → return that provider if registered; else fall through
	 *   - 'default' → fall through to site-wide activeProvider
	 *
	 * Returns null to signal "use the fallback (text) directly".
	 */
	private function resolveProviderForQuery(SearchQuery $query): ?SearchProvider
	{
		// Per-collection override
		if ($query->collection !== null) {
			$override = $this->resolveCollectionOverride($query->collection);
			if ($override === 'text') {
				return null; // force text fallback
			}
			if ($override !== null && $override !== 'default') {
				$named = $this->registry->active($override);
				if ($named !== null) {
					return $named;
				}
				// Declared override not registered — fall through to site-wide
			}
		}

		// Site-wide active provider
		$activeId = (string)($this->config->search['activeProvider'] ?? 'text');
		if ($activeId === 'text') {
			return null;
		}
		return $this->registry->active($activeId);
	}

	/**
	 * Read collection.mcp.searchProvider; return null when absent or empty.
	 */
	private function resolveCollectionOverride(string $collectionId): ?string
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionId);
		if ($collection === null) {
			return null;
		}
		$value = (string)($collection->mcp['searchProvider'] ?? '');
		return $value === '' ? null : $value;
	}
}
