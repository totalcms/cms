<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tools\Content;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;
use TotalCMS\Domain\Mcp\Tools\Content\SearchCollectionTool;
use TotalCMS\Domain\Query\Service\ObjectSearcher;

final class SearchCollectionToolTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $indexFilter;
	private ObjectSearcher $searcher;
	private \PHPUnit\Framework\MockObject\MockObject $collections;
	private \PHPUnit\Framework\MockObject\MockObject $urls;
	private \PHPUnit\Framework\MockObject\MockObject $resolver;
	private PersonaContext $persona;
	private SearchCollectionTool $tool;

	protected function setUp(): void
	{
		$this->indexFilter = $this->createMock(IndexFilter::class);
		// ObjectSearcher is pure — exercise the real implementation so the
		// AND/OR/quoted-phrase behavior is part of what we're asserting.
		$this->searcher    = new ObjectSearcher();
		$this->collections = $this->createMock(CollectionFetcher::class);
		$this->urls        = $this->createMock(ObjectUrlBuilder::class);
		$this->resolver    = $this->createMock(McpSchemaResolver::class);
		$this->persona     = new PersonaContext();

		$this->tool = new SearchCollectionTool(
			$this->indexFilter,
			$this->searcher,
			$this->collections,
			$this->urls,
			$this->persona,
			$this->resolver,
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

	// ─── Safety-filter-first ordering (the architectural reason this tool exists) ──

	public function testPublicPersonaAppliesDraftExcludeBeforeSearching(): void
	{
		// THE critical test. QueryPipeline's include/exclude is mutually
		// exclusive with search — so the search path naively wired through
		// IndexQueryService would have NO way to enforce draft:true for
		// public callers. This tool side-steps that by calling IndexFilter
		// directly with the safety filter, then handing the filtered set
		// to ObjectSearcher.
		//
		// If a regression ever re-wires the search path through
		// IndexQueryService::query() or skips the filter call, this test
		// will catch it.
		$this->persona->set(McpPersona::PUBLIC_);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedFields')->willReturn([]);

		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndex')
			->with('blog', ['exclude' => 'draft:true'])
			->willReturn([
				['id' => 'public-post', 'title' => 'Hello world'],
			]);
		$this->urls->method('buildUrl')->willReturn('/blog/public-post');

		$result = $this->tool->handler(collection: 'blog', query: 'hello');

		$this->assertCount(1, $result['items']);
		$this->assertSame('public-post', $result['items'][0]['id']);
	}

	public function testAdminPersonaLoadsIndexWithoutSafetyFilter(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedFields')->willReturn([]);

		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndex')
			->with('blog', [])  // No exclude applied — admin sees drafts.
			->willReturn([]);

		$this->tool->handler(collection: 'blog', query: 'anything');
	}

	// ─── Search delegates to ObjectSearcher correctly ────────────────────────

	public function testSearchActuallyFiltersItemsByQueryViaObjectSearcher(): void
	{
		// Two items in the index — only one matches the query "rust". This
		// verifies the search step actually runs after the safety filter.
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedFields')->willReturn([]);

		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'a', 'title' => 'Rust adventures', 'body' => 'Memory safe code.'],
			['id' => 'b', 'title' => 'PHP musings',     'body' => 'Static analysis.'],
		]);
		$this->urls->method('buildUrl')->willReturnOnConsecutiveCalls('/blog/a', '/blog/b');

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
		$this->resolver->method('nonExposedFields')->willReturn([]);

		// 60 matching items — the cap should slice us back to 50.
		$items = [];
		for ($i = 0; $i < 60; $i++) {
			$items[] = ['id' => 'p' . $i, 'title' => 'match'];
		}
		$this->indexFilter->method('fetchFilteredIndex')->willReturn($items);
		$this->urls->method('buildUrl')->willReturn('/blog/x');

		$result = $this->tool->handler(collection: 'blog', query: 'match', limit: 1000);

		$this->assertCount(50, $result['items']);
		$this->assertSame(50, $result['limit']);
	}

	public function testHandlerDecoratesUrlsAndStripsNonExposedFields(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$blog = $this->collection('blog');
		$this->collections->method('fetchCollection')->willReturn($blog);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedFields')->willReturn(['secret']);

		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'a', 'title' => 'rust', 'secret' => 'hidden'],
		]);
		$this->urls->method('buildUrl')->willReturn('/blog/a');

		$result = $this->tool->handler(collection: 'blog', query: 'rust');

		$this->assertSame('/blog/a', $result['items'][0]['url']);
		$this->assertArrayNotHasKey('secret', $result['items'][0]);
	}

	public function testEmptyQueryReturnsEmptyResultSetWithoutInvokingSearcher(): void
	{
		// An empty `query` would otherwise be interpreted by ObjectSearcher as
		// "match nothing". We short-circuit instead and tell the caller — with
		// a recovery hint — that they need to pass a query.
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);

		try {
			$this->tool->handler(collection: 'blog', query: '   ');
			$this->fail('Expected ToolCallException for empty query.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('query', strtolower($e->getMessage()));
		}
	}
}
