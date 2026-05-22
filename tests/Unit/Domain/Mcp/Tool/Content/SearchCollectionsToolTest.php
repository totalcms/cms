<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Twig\Markdown\TiptapToMarkdownConverter;
use TotalCMS\Domain\Mcp\Tool\Content\SearchCollectionsTool;
use TotalCMS\Domain\Query\Service\ObjectSearcher;

final class SearchCollectionsToolTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $indexFilter;
	private ObjectSearcher $searcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionsRepo;
	private \PHPUnit\Framework\MockObject\MockObject $urls;
	private \PHPUnit\Framework\MockObject\MockObject $resolver;
	private PersonaContext $persona;
	private SearchCollectionsTool $tool;

	protected function setUp(): void
	{
		$this->indexFilter     = $this->createMock(IndexFilter::class);
		$this->searcher        = new ObjectSearcher();
		$this->collectionsRepo = $this->createMock(CollectionRepository::class);
		$this->urls            = $this->createMock(ObjectUrlBuilder::class);
		$this->resolver        = $this->createMock(McpSchemaResolver::class);
		$this->persona         = new PersonaContext();

		$this->tool = new SearchCollectionsTool(
			$this->indexFilter,
			$this->searcher,
			$this->collectionsRepo,
			$this->urls,
			$this->persona,
			$this->resolver,
			new ContentRenderer(new TiptapToMarkdownConverter()),
		);
	}

	private function collection(string $id, string $access = 'public'): CollectionData
	{
		$collection         = new CollectionData();
		$collection->id     = $id;
		$collection->schema = $id;
		$collection->mcp    = ['access' => $access];

		return $collection;
	}

	// ─── Registration ────────────────────────────────────────────────────────

	public function testRegisterAddsToolWithExpectedNameAndPublicAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$definition = $registry->get('search_collections');
		$this->assertNotNull($definition);
		$this->assertSame('public', $definition->access);
	}

	public function testInputSchemaRequiresQueryAndCapsLimitAtFifty(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$schema = $registry->get('search_collections')->inputSchema;

		$this->assertSame(['query'], $schema['required']);
		$this->assertSame(50, $schema['properties']['limit']['maximum']);
		$this->assertNotEmpty($schema['properties']['query']['examples']);
	}

	public function testDescriptionPointsAtListCollectionsForCatalog(): void
	{
		// Cross-collection — too many filterable_fields to embed in the
		// description without exploding. Point the agent at list_collections
		// for the catalog instead, like the single-collection tools do
		// inline.
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$description = $registry->get('search_collections')->description;

		$this->assertStringContainsString('list_collections', $description);
		$this->assertStringContainsString('get_object', $description);
	}

	// ─── Error recovery hints ────────────────────────────────────────────────

	public function testEmptyQueryRejectedWithRecoveryHint(): void
	{
		$this->persona->set(McpPersona::ADMIN);

		try {
			$this->tool->handler(query: '  ');
			$this->fail('Expected ToolCallException for empty query.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('query', strtolower($e->getMessage()));
		}
	}

	// ─── Persona-aware safety filter applied per-collection BEFORE search ────

	public function testPublicPersonaSearchesOnlyPublicCollectionsWithDraftExcluded(): void
	{
		// THE critical test for C2. Public must NOT see results from admin-only
		// collections AND must not see drafts within public collections. The
		// safety filter applies per-collection BEFORE ObjectSearcher runs —
		// same architecture as SearchCollectionTool (B3) but iterated across
		// every visible collection.
		$this->persona->set(McpPersona::PUBLIC_);

		$blog = $this->collection('blog', access: 'public');
		$auth = $this->collection('auth', access: 'admin');

		$this->collectionsRepo->method('listAllCollections')->willReturn([$blog, $auth]);
		$this->resolver->method('isAccessibleTo')->willReturnCallback(
			fn (CollectionData $c, string $p): bool => ($c->mcp['access'] ?? '') === 'public',
		);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		// Only `blog` should be queried — `auth` must not even hit
		// fetchFilteredIndex. And blog's fetch must include the safety filter.
		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndex')
			->with('blog', ['exclude' => 'draft:true'])
			->willReturn([
				['id' => 'public-post', 'title' => 'hello world'],
			]);
		$this->urls->method('buildUrl')->willReturn('/blog/public-post');

		$result = $this->tool->handler(query: 'hello');

		$this->assertCount(1, $result['items']);
		$this->assertSame('public-post', $result['items'][0]['id']);
	}

	public function testAdminPersonaSearchesEveryCollectionWithoutSafetyFilter(): void
	{
		$this->persona->set(McpPersona::ADMIN);

		$blog = $this->collection('blog');
		$auth = $this->collection('auth', access: 'admin');

		$this->collectionsRepo->method('listAllCollections')->willReturn([$blog, $auth]);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		// Both collections must be visited; admin sees drafts so options stay empty.
		$expectations = [];
		$this->indexFilter->expects($this->exactly(2))
			->method('fetchFilteredIndex')
			->willReturnCallback(function (string $collection, array $opts) use (&$expectations): array {
				$expectations[] = [$collection, $opts];

				return [];
			});

		$this->tool->handler(query: 'admin');

		$this->assertSame([['blog', []], ['auth', []]], $expectations);
	}

	// ─── Result decoration ───────────────────────────────────────────────────

	public function testEachResultItemCarriesCollectionFieldForGetObjectChaining(): void
	{
		// Cross-collection results need to tell the agent which collection
		// each hit came from so it can chain into get_object correctly.
		// Without this the agent has no way to know.
		$this->persona->set(McpPersona::ADMIN);

		$blog     = $this->collection('blog');
		$products = $this->collection('products');

		$this->collectionsRepo->method('listAllCollections')->willReturn([$blog, $products]);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);
		$this->indexFilter->method('fetchFilteredIndex')->willReturnOnConsecutiveCalls(
			[['id' => 'post-a', 'title' => 'rust adventures']],
			[['id' => 'widget',  'name'  => 'rust mug']],
		);
		$this->urls->method('buildUrl')->willReturnOnConsecutiveCalls('/blog/post-a', '/products/widget');

		$result = $this->tool->handler(query: 'rust');

		$collections = array_column($result['items'], 'collection');
		$this->assertContains('blog', $collections);
		$this->assertContains('products', $collections);
	}

	public function testHandlerStripsNonExposedFieldsPerCollection(): void
	{
		// Each collection has its OWN non-exposed list — the stripping must
		// be applied per-collection, not globally. Verify by giving blog a
		// secret field while products has none, and checking only blog's
		// item has the field stripped.
		$this->persona->set(McpPersona::ADMIN);

		$blog     = $this->collection('blog');
		$products = $this->collection('products');

		$this->collectionsRepo->method('listAllCollections')->willReturn([$blog, $products]);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturnCallback(
			static fn (CollectionData $c): array => $c->id === 'blog' ? ['internal_notes'] : [],
		);
		$this->indexFilter->method('fetchFilteredIndex')->willReturnOnConsecutiveCalls(
			[['id' => 'a', 'title' => 'match', 'internal_notes' => 'private']],
			[['id' => 'b', 'name'  => 'match', 'internal_notes' => 'kept']],
		);
		$this->urls->method('buildUrl')->willReturn('/x');

		$result = $this->tool->handler(query: 'match');

		$byCollection = [];
		foreach ($result['items'] as $item) {
			$byCollection[$item['collection']] = $item;
		}
		$this->assertArrayNotHasKey('internal_notes', $byCollection['blog']);
		$this->assertSame('kept', $byCollection['products']['internal_notes']);
	}

	public function testHandlerClampsAggregateLimitAtFifty(): void
	{
		// Limit applies to the AGGREGATE across all collections — preventing
		// a runaway response when several collections each have many hits.
		$this->persona->set(McpPersona::ADMIN);

		$collections = [];
		for ($i = 0; $i < 3; $i++) {
			$collections[] = $this->collection('c' . $i);
		}
		$this->collectionsRepo->method('listAllCollections')->willReturn($collections);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		// Each collection returns 30 matches → 90 total → must clamp to 50.
		$items = [];
		for ($i = 0; $i < 30; $i++) {
			$items[] = ['id' => 'i' . $i, 'title' => 'match'];
		}
		$this->indexFilter->method('fetchFilteredIndex')->willReturn($items);
		$this->urls->method('buildUrl')->willReturn('/x');

		$result = $this->tool->handler(query: 'match', limit: 1000);

		$this->assertCount(50, $result['items']);
		$this->assertSame(50, $result['limit']);
	}

	public function testHandlerReturnsEnvelopeWithQueryEcho(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->collectionsRepo->method('listAllCollections')->willReturn([]);

		$result = $this->tool->handler(query: 'anything');

		$this->assertSame([], $result['items']);
		$this->assertSame(0, $result['total']);
		$this->assertSame('anything', $result['query']);
	}
}
