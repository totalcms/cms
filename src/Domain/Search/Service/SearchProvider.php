<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Search\Service;

use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Data\SearchResult;

/**
 * Extension contract for search providers.
 *
 * Implementations bridge T3's content events + queries to an external
 * search backend (Algolia, OpenAI embeddings, Meilisearch, etc.). T3
 * core ships `TextSearchProvider` as the built-in default that wraps
 * the existing IndexSearcher; extensions ship others via
 * `ExtensionContext::registerSearchProvider()`.
 *
 * Lifecycle:
 *   - `index()` and `delete()` are called by ContentChangeListener on
 *     object.created / object.updated / object.deleted events.
 *   - `search()` is called by SearchService whenever a caller (MCP tool,
 *     future REST endpoint, future site-wide extension) wants results.
 *   - `isAvailable()` is a fast health check; SearchService falls back
 *     to text search when this returns false.
 *
 * Failure model:
 *   - Throwing from `search()` triggers silent fallback to text search.
 *   - Throwing from `index()` / `delete()` is caught by the listener
 *     and enqueued as a `ReindexJob` for later retry.
 */
interface SearchProvider
{
	/**
	 * Stable identifier — lowercase alphanumeric + hyphens (e.g. 'algolia',
	 * 'openai', 'meilisearch', 'text'). Used in settings + per-schema
	 * opt-outs. Must NOT change across versions; collision is a registry
	 * error.
	 */
	public function id(): string;

	/**
	 * Human-readable name for admin UI. Translatable on the provider side.
	 */
	public function label(): string;

	/**
	 * Execute a query against the provider's index. Returns ranked results.
	 *
	 * @return list<SearchResult>
	 */
	public function search(SearchQuery $query): array;

	/**
	 * Index (or re-index) one object.
	 *
	 * Implementations must be idempotent — indexing the same id twice
	 * with different data updates the indexed entry, doesn't error.
	 *
	 * @param array<string,mixed> $data Object data as stored in T3
	 */
	public function index(string $collection, string $id, array $data): void;

	/**
	 * Remove one object from the index. Idempotent — deleting an
	 * already-deleted id is a no-op, not an error.
	 */
	public function delete(string $collection, string $id): void;

	/**
	 * Health check. SearchService calls this before each search. Implementations
	 * SHOULD cache the result with a short TTL (e.g. 30s via CacheManager) — this
	 * is on the hot path.
	 *
	 * Returning false routes the request to the text fallback silently.
	 */
	public function isAvailable(): bool;
}
