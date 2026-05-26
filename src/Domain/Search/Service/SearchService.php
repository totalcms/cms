<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Search\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Support\Config;

/**
 * Public face of the search abstraction. MCP tools, future REST endpoints,
 * and the future site-wide search extension call `search()` and don't need
 * to know which provider runs underneath.
 *
 * Resolution:
 *   1. Read `search.activeProvider` from Config — defaults to 'text'.
 *   2. If 'text' → route to TextSearchProvider directly.
 *   3. Otherwise look up the active provider in the registry.
 *   4. If missing OR isAvailable() returns false → fall back to text.
 *   5. Call provider->search(). On any exception → log + fall back to text.
 */
final readonly class SearchService implements SearchServiceInterface
{
	public function __construct(
		private SearchProviderRegistry $registry,
		private SearchProvider $fallback,
		private LoggerInterface $logger,
		private Config $config,
	) {
	}

	/**
	 * @return list<\TotalCMS\Domain\Search\Data\SearchResult>
	 */
	public function search(SearchQuery $query): array
	{
		$activeId = (string)($this->config->search['activeProvider'] ?? 'text');

		// Built-in or unconfigured → text search directly.
		if ($activeId === 'text') {
			return $this->fallback->search($query);
		}

		$provider = $this->registry->active($activeId);
		if ($provider === null || !$provider->isAvailable()) {
			$this->logger->info('Active search provider unavailable; falling back to text search', [
				'requested' => $activeId,
				'present'   => $provider !== null,
			]);
			return $this->fallback->search($query);
		}

		try {
			return $provider->search($query);
		} catch (\Throwable $e) {
			$this->logger->warning('Search provider threw; falling back to text search', [
				'provider' => $activeId,
				'error'    => $e->getMessage(),
				'query'    => $query->text,
			]);
			return $this->fallback->search($query);
		}
	}
}
