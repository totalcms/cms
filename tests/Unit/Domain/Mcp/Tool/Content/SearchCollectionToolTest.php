<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Content\SearchCollectionTool;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Data\SearchResult;
use TotalCMS\Domain\Search\Service\SearchServiceInterface;
use TotalCMS\Domain\Twig\Markdown\TiptapToMarkdownConverter;

final class SearchCollectionToolTest extends TestCase
{
	/** @var \PHPUnit\Framework\MockObject\MockObject&SearchServiceInterface */
	private \PHPUnit\Framework\MockObject\MockObject $searchService;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collections;
	private \PHPUnit\Framework\MockObject\MockObject $urls;
	private \PHPUnit\Framework\MockObject\MockObject $resolver;
	private PersonaContext $persona;
	private SearchCollectionTool $tool;

	protected function setUp(): void
	{
		$this->searchService = $this->createMock(SearchServiceInterface::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->collections   = $this->createMock(CollectionFetcher::class);
		$this->urls          = $this->createMock(ObjectUrlBuilder::class);
		$this->resolver      = $this->createMock(McpSchemaResolver::class);
		$this->persona       = new PersonaContext();

		$this->tool = new SearchCollectionTool(
			$this->searchService,
			$this->collections,
			$this->objectFetcher,
			$this->urls,
			$this->persona,
			$this->resolver,
			new ContentRenderer(new TiptapToMarkdownConverter()),
		);
	}

	private function collection(string $id = 'blog'): CollectionData
	{
		$collection         = new CollectionData();
		$collection->id     = $id;
		$collection->schema = $id;
		$collection->mcp    = ['access' => 'public'];

		return $collection;
	}

	/**
	 * Helper: build an ObjectData mock whose toArray() returns the given array.
	 *
	 * @param array<string,mixed> $data
	 */
	private function objectDataMock(array $data): ObjectData
	{
		$mock = $this->createMock(ObjectData::class);
		$mock->method('toArray')->willReturn($data);

		return $mock;
	}

	// ─── Registration ────────────────────────────────────────────────────────

	public function testRegisterAddsToolWithExpectedNameAndAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$definition = $registry->get('search_collection');
		$this->assertNotNull($definition);
		$this->assertSame('public', $definition->access);
		$this->assertNotNull($definition->descriptionBuilder);
	}

	public function testInputSchemaRequiresNameAndQuery(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$schema = $registry->get('search_collection')->inputSchema;

		$this->assertEqualsCanonicalizing(['collection', 'query'], $schema['required']);
		$this->assertNotEmpty($schema['properties']['query']['examples']);
		$this->assertSame(50, $schema['properties']['limit']['maximum']);
	}

	// ─── Error recovery hints ────────────────────────────────────────────────

	public function testUnknownCollectionThrowsRecoveryHint(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn(null);

		try {
			$this->tool->handler(collection: 'blug', query: 'hello');
			$this->fail('Expected ToolCallException for unknown collection.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('blug', $e->getMessage());
			$this->assertStringContainsString('list_collections', $e->getMessage());
		}
	}

	public function testCollectionInaccessibleToPersonaThrowsRecoveryHint(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->collections->method('fetchCollection')->willReturn($this->collection('auth'));
		$this->resolver->method('isAccessibleTo')->willReturn(false);

		try {
			$this->tool->handler(collection: 'auth', query: 'admin');
			$this->fail('Expected ToolCallException for inaccessible collection.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('auth', $e->getMessage());
		}
	}

	// ─── Persona forwarded to SearchService (the architectural safety guarantee) ─

	public function testPublicPersonaForwardsPublicPersonaToSearchService(): void
	{
		// THE critical test. Public callers must never see drafts. The persona
		// is forwarded in SearchQuery so TextSearchProvider applies
		// `exclude: draft:true` inside the provider before ObjectSearcher runs.
		// If a regression strips the persona from SearchQuery, drafts leak.
		$this->persona->set(McpPersona::PUBLIC_);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		$capturedQuery = null;
		$this->searchService->expects($this->once())
			->method('search')
			->willReturnCallback(function (SearchQuery $q) use (&$capturedQuery): array {
				$capturedQuery = $q;

				return [new SearchResult(collection: 'blog', id: 'public-post', score: 1.0)];
			});

		$this->objectFetcher->method('existsObject')->willReturn(true);
		$this->objectFetcher->method('fetchObject')
			->willReturn($this->objectDataMock(['id' => 'public-post', 'title' => 'Hello world']));
		$this->urls->method('buildUrl')->willReturn('/blog/public-post');

		$result = $this->tool->handler(collection: 'blog', query: 'hello');

		$this->assertSame('public', $capturedQuery->persona);
		$this->assertCount(1, $result['items']);
		$this->assertSame('public-post', $result['items'][0]['id']);
	}

	public function testAdminPersonaForwardsAdminPersonaToSearchService(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		$capturedQuery = null;
		$this->searchService->expects($this->once())
			->method('search')
			->willReturnCallback(function (SearchQuery $q) use (&$capturedQuery): array {
				$capturedQuery = $q;

				return [];
			});

		$this->tool->handler(collection: 'blog', query: 'anything');

		// Admin persona must be forwarded so the provider does not apply draft exclusion.
		$this->assertSame('admin', $capturedQuery->persona);
	}

	// ─── Search delegates to SearchService correctly ─────────────────────────

	public function testSearchActuallyFiltersItemsByQueryViaSearchService(): void
	{
		// SearchService returns only matching results; the handler resolves
		// those result IDs via ObjectFetcher. This verifies the full pipeline
		// from SearchService result → ObjectFetcher → shaped output.
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		// SearchService returns only the rust-matching post.
		$this->searchService->method('search')->willReturn([
			new SearchResult(collection: 'blog', id: 'a', score: 1.0),
		]);

		$this->objectFetcher->method('existsObject')->willReturn(true);
		$this->objectFetcher->method('fetchObject')
			->with('blog', 'a')
			->willReturn($this->objectDataMock(['id' => 'a', 'title' => 'Rust adventures', 'body' => 'Memory safe code.']));
		$this->urls->method('buildUrl')->willReturn('/blog/a');

		$result = $this->tool->handler(collection: 'blog', query: 'rust');

		$this->assertCount(1, $result['items']);
		$this->assertSame('a', $result['items'][0]['id']);
	}

	// ─── Result shaping ──────────────────────────────────────────────────────

	public function testHandlerClampsLimitAtFifty(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		// SearchService returns 60 results — the cap at LIMIT_CAP=50 must apply.
		$results = [];
		for ($i = 0; $i < 60; $i++) {
			$results[] = new SearchResult(collection: 'blog', id: 'p' . $i, score: 1.0);
		}

		$capturedQuery = null;
		$this->searchService->expects($this->once())
			->method('search')
			->willReturnCallback(function (SearchQuery $q) use (&$capturedQuery, $results): array {
				$capturedQuery = $q;

				// Return only up to the requested limit (simulating what SearchService does).
				return array_slice($results, 0, $q->limit);
			});

		$this->objectFetcher->method('existsObject')->willReturn(true);
		$this->objectFetcher->method('fetchObject')
			->willReturnCallback(fn (string $col, string $id): ObjectData => $this->objectDataMock(['id' => $id, 'title' => 'match']));
		$this->urls->method('buildUrl')->willReturn('/blog/x');

		$result = $this->tool->handler(collection: 'blog', query: 'match', limit: 1000);

		$this->assertSame(50, $capturedQuery->limit);
		$this->assertCount(50, $result['items']);
		$this->assertSame(50, $result['limit']);
	}

	public function testHandlerDecoratesUrlsAndStripsNonExposedFields(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$blog = $this->collection('blog');
		$this->collections->method('fetchCollection')->willReturn($blog);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn(['secret']);

		$this->searchService->method('search')->willReturn([
			new SearchResult(collection: 'blog', id: 'a', score: 1.0),
		]);
		$this->objectFetcher->method('existsObject')->willReturn(true);
		$this->objectFetcher->method('fetchObject')
			->willReturn($this->objectDataMock(['id' => 'a', 'title' => 'rust', 'secret' => 'hidden']));
		$this->urls->method('buildUrl')->willReturn('/blog/a');

		$result = $this->tool->handler(collection: 'blog', query: 'rust');

		$this->assertSame('/blog/a', $result['items'][0]['url']);
		$this->assertArrayNotHasKey('secret', $result['items'][0]);
	}

	public function testEmptyQueryReturnsEmptyResultSetWithoutInvokingSearcher(): void
	{
		// An empty `query` short-circuits before SearchService is called.
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);

		$this->searchService->expects($this->never())->method('search');

		try {
			$this->tool->handler(collection: 'blog', query: '   ');
			$this->fail('Expected ToolCallException for empty query.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('query', strtolower($e->getMessage()));
		}
	}
}
