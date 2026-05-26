<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Search\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
		$active = $this->makeProvider('algolia', searchReturn: [$activeResult], available: true);
		$fallback = $this->makeFallback([]);

		$registry = new SearchProviderRegistry();
		$registry->register($active);

		$config = $this->makeConfig(['activeProvider' => 'algolia']);

		$service = new SearchService($registry, $fallback, new NullLogger(), $config);

		$out = $service->search(new SearchQuery(text: 'hello', collection: 'blog'));

		$this->assertCount(1, $out);
		$this->assertSame('a', $out[0]->id);
	}

	public function testFallsBackToTextWhenActiveIdMissingFromRegistry(): void
	{
		$fallbackResult = new SearchResult(collection: 'blog', id: 'fb', score: 0.5);
		$fallback = $this->makeFallback([$fallbackResult]);

		$registry = new SearchProviderRegistry();
		// algolia not registered

		$config = $this->makeConfig(['activeProvider' => 'algolia']);

		$service = new SearchService($registry, $fallback, new NullLogger(), $config);

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

		$service = new SearchService($registry, $fallback, new NullLogger(), $config);

		$out = $service->search(new SearchQuery(text: 'hello', collection: 'blog'));

		$this->assertSame('fb', $out[0]->id);
	}

	public function testFallsBackWhenProviderThrows(): void
	{
		$active = $this->makeProvider('algolia', throwInSearch: new \RuntimeException('boom'), available: true);
		$fallback = $this->makeFallback([new SearchResult('blog', 'fb', 0.5)]);

		$registry = new SearchProviderRegistry();
		$registry->register($active);

		$config = $this->makeConfig(['activeProvider' => 'algolia']);

		$service = new SearchService($registry, $fallback, new NullLogger(), $config);

		$out = $service->search(new SearchQuery(text: 'hello', collection: 'blog'));

		$this->assertSame('fb', $out[0]->id);
	}

	public function testUsesFallbackWhenActiveProviderIsText(): void
	{
		$fallback = $this->makeFallback([new SearchResult('blog', 'fb', 0.5)]);

		$registry = new SearchProviderRegistry();
		$config   = $this->makeConfig(['activeProvider' => 'text']);

		$service = new SearchService($registry, $fallback, new NullLogger(), $config);

		$out = $service->search(new SearchQuery(text: 'hello', collection: 'blog'));

		$this->assertSame('fb', $out[0]->id);
	}

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
			) {}

			public function id(): string { return $this->id; }
			public function label(): string { return ucfirst($this->id); }
			public function isAvailable(): bool { return $this->available; }

			public function search(SearchQuery $query): array
			{
				if ($this->throwInSearch !== null) {
					throw $this->throwInSearch;
				}
				return $this->searchReturn;
			}

			public function index(string $collection, string $id, array $data): void {}
			public function delete(string $collection, string $id): void {}
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
}
