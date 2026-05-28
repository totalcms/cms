<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Search\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Data\SearchResult;
use TotalCMS\Domain\Search\Service\SearchProvider;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Domain\Search\Service\SearchService;
use TotalCMS\Support\Config;

final class SearchServiceTest extends TestCase
{
	public function testRoutesToActiveProviderWhenConfigured(): void
	{
		$activeResult = new SearchResult(collection: 'blog', id: 'a', score: 0.9);
		$active       = $this->makeProvider('algolia', searchReturn: [$activeResult], available: true);
		$fallback     = $this->makeFallback([]);

		$registry = new SearchProviderRegistry();
		$registry->register($active);

		$config = $this->makeConfig(['activeProvider' => 'algolia']);

		$service = $this->makeService($registry, $fallback, $config);

		$out = $service->search(new SearchQuery(text: 'hello', collection: 'blog'));

		$this->assertCount(1, $out);
		$this->assertSame('a', $out[0]->id);
	}

	public function testFallsBackToTextWhenActiveIdMissingFromRegistry(): void
	{
		$fallbackResult = new SearchResult(collection: 'blog', id: 'fb', score: 0.5);
		$fallback       = $this->makeFallback([$fallbackResult]);

		$registry = new SearchProviderRegistry();
		// algolia not registered

		$config = $this->makeConfig(['activeProvider' => 'algolia']);

		$service = $this->makeService($registry, $fallback, $config);

		$out = $service->search(new SearchQuery(text: 'hello', collection: 'blog'));

		$this->assertSame('fb', $out[0]->id);
	}

	public function testFallsBackWhenActiveProviderNotAvailable(): void
	{
		$active   = $this->makeProvider('algolia', searchReturn: [], available: false);
		$fallback = $this->makeFallback([new SearchResult('blog', 'fb', 0.5)]);

		$registry = new SearchProviderRegistry();
		$registry->register($active);

		$config = $this->makeConfig(['activeProvider' => 'algolia']);

		$service = $this->makeService($registry, $fallback, $config);

		$out = $service->search(new SearchQuery(text: 'hello', collection: 'blog'));

		$this->assertSame('fb', $out[0]->id);
	}

	public function testFallsBackWhenProviderThrows(): void
	{
		$active   = $this->makeProvider('algolia', throwInSearch: new \RuntimeException('boom'), available: true);
		$fallback = $this->makeFallback([new SearchResult('blog', 'fb', 0.5)]);

		$registry = new SearchProviderRegistry();
		$registry->register($active);

		$config = $this->makeConfig(['activeProvider' => 'algolia']);

		$service = $this->makeService($registry, $fallback, $config);

		$out = $service->search(new SearchQuery(text: 'hello', collection: 'blog'));

		$this->assertSame('fb', $out[0]->id);
	}

	public function testUsesFallbackWhenActiveProviderIsText(): void
	{
		$fallback = $this->makeFallback([new SearchResult('blog', 'fb', 0.5)]);

		$registry = new SearchProviderRegistry();
		$config   = $this->makeConfig(['activeProvider' => 'text']);

		$service = $this->makeService($registry, $fallback, $config);

		$out = $service->search(new SearchQuery(text: 'hello', collection: 'blog'));

		$this->assertSame('fb', $out[0]->id);
	}

	// -------------------------------------------------------------------------
	// Per-collection override tests
	// -------------------------------------------------------------------------

	public function testPerCollectionOverrideForcesTextSearch(): void
	{
		// Collection 'products' has mcp.searchProvider = 'text'. Site-wide is algolia.
		// Query should route to fallback (text search).
		$algolia = $this->makeProvider('algolia', searchReturn: [
			new SearchResult('products', 'algolia-hit', 1.0),
		], available: true);
		$fallback = $this->makeFallback([
			new SearchResult('products', 'text-hit', 0.5),
		]);

		$registry = new SearchProviderRegistry();
		$registry->register($algolia);

		$config  = $this->makeConfig(['activeProvider' => 'algolia']);
		$fetcher = $this->makeCollectionFetcher('products', ['searchProvider' => 'text']);

		$service = new SearchService($registry, $fallback, new NullLogger(), $config, $fetcher);

		$out = $service->search(new SearchQuery(text: 'sku-123', collection: 'products'));

		$this->assertSame('text-hit', $out[0]->id);  // fallback, not algolia
	}

	public function testPerCollectionOverrideRoutesToNamedProvider(): void
	{
		// Collection 'docs' has mcp.searchProvider = 'algolia'. Site-wide is 'text'.
		// Query should route to algolia.
		$algolia = $this->makeProvider('algolia', searchReturn: [
			new SearchResult('docs', 'algolia-hit', 1.0),
		], available: true);
		$fallback = $this->makeFallback([]);

		$registry = new SearchProviderRegistry();
		$registry->register($algolia);

		$config  = $this->makeConfig(['activeProvider' => 'text']);
		$fetcher = $this->makeCollectionFetcher('docs', ['searchProvider' => 'algolia']);

		$service = new SearchService($registry, $fallback, new NullLogger(), $config, $fetcher);

		$out = $service->search(new SearchQuery(text: 'hello', collection: 'docs'));

		$this->assertSame('algolia-hit', $out[0]->id);
	}

	public function testDefaultOverrideFallsThroughToSiteWide(): void
	{
		// mcp.searchProvider = 'default' means use site-wide. Site-wide is algolia.
		// Should route to algolia.
		$algolia = $this->makeProvider('algolia', searchReturn: [
			new SearchResult('blog', 'algolia-hit', 1.0),
		], available: true);
		$fallback = $this->makeFallback([]);

		$registry = new SearchProviderRegistry();
		$registry->register($algolia);

		$config  = $this->makeConfig(['activeProvider' => 'algolia']);
		$fetcher = $this->makeCollectionFetcher('blog', ['searchProvider' => 'default']);

		$service = new SearchService($registry, $fallback, new NullLogger(), $config, $fetcher);

		$out = $service->search(new SearchQuery(text: 'hello', collection: 'blog'));

		$this->assertSame('algolia-hit', $out[0]->id);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @param list<SearchResult> $searchReturn */
	private function makeProvider(
		string $id,
		array $searchReturn = [],
		bool $available = true,
		?\Throwable $throwInSearch = null,
	): SearchProvider {
		return new class($id, $searchReturn, $available, $throwInSearch) implements SearchProvider {
			/** @param list<SearchResult> $searchReturn */
			public function __construct(
				private readonly string $id,
				private readonly array $searchReturn,
				private readonly bool $available,
				private readonly ?\Throwable $throwInSearch,
			) {
			}

			public function id(): string
			{
				return $this->id;
			}

			public function label(): string
			{
				return ucfirst($this->id);
			}

			public function isAvailable(): bool
			{
				return $this->available;
			}

			public function search(SearchQuery $query): array
			{
				if ($this->throwInSearch !== null) {
					throw $this->throwInSearch;
				}

				return $this->searchReturn;
			}

			public function index(string $collection, string $id, array $data): void
			{
			}

			public function delete(string $collection, string $id): void
			{
			}
		};
	}

	/** @param list<SearchResult> $searchReturn */
	private function makeFallback(array $searchReturn): SearchProvider
	{
		// TextSearchProvider is final readonly — we can't subclass it in tests.
		// SearchService accepts SearchProvider for its fallback so we can pass
		// any provider implementation; in production the container wires in
		// the real TextSearchProvider.
		return $this->makeProvider('text', searchReturn: $searchReturn, available: true);
	}

	/** @param array<string,mixed> $search */
	private function makeConfig(array $search): Config
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty($config, 'search'))->setValue($config, $search);

		return $config;
	}

	/**
	 * Build a CollectionFetcher stub that returns a collection with the given
	 * mcp array for $collectionId, and null for any other collection.
	 *
	 * @param array<string,mixed> $mcp
	 */
	private function makeCollectionFetcher(string $collectionId, array $mcp): CollectionFetcher
	{
		$fetcher = $this->createMock(CollectionFetcher::class);

		$collection = (new \ReflectionClass(CollectionData::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty($collection, 'mcp'))->setValue($collection, $mcp);

		$fetcher->method('fetchCollection')
			->with($collectionId)
			->willReturn($collection);

		return $fetcher;
	}

	/**
	 * Build a SearchService with a default no-override CollectionFetcher.
	 * Existing tests use this so they don't need to care about the new dep.
	 */
	private function makeService(
		SearchProviderRegistry $registry,
		SearchProvider $fallback,
		Config $config,
		?CollectionFetcher $fetcher = null,
	): SearchService {
		if ($fetcher === null) {
			$fetcher = $this->createMock(CollectionFetcher::class);
			$fetcher->method('fetchCollection')->willReturn(null);
		}

		return new SearchService($registry, $fallback, new NullLogger(), $config, $fetcher);
	}
}
