<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Content\QueryCollectionTool;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Twig\Markdown\TiptapToMarkdownConverter;

final class QueryCollectionToolTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $indexQuery;
	private \PHPUnit\Framework\MockObject\MockObject $collections;
	private \PHPUnit\Framework\MockObject\MockObject $urls;
	private \PHPUnit\Framework\MockObject\MockObject $resolver;
	private PersonaContext $persona;
	private QueryCollectionTool $tool;

	protected function setUp(): void
	{
		$this->indexQuery  = $this->createMock(IndexQueryService::class);
		$this->collections = $this->createMock(CollectionFetcher::class);
		$this->urls        = $this->createMock(ObjectUrlBuilder::class);
		$this->resolver    = $this->createMock(McpSchemaResolver::class);
		$this->persona     = new PersonaContext();

		$this->tool = new QueryCollectionTool(
			$this->indexQuery,
			$this->collections,
			$this->urls,
			$this->persona,
			$this->resolver,
			// Real ContentRenderer with the real converter — pure functions, no
			// IO. Lets the rendering tests assert actual markdown output instead
			// of mock contract.
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

	// ─── Registration shape ───────────────────────────────────────────────────

	public function testRegisterAddsToolWithExpectedNameAndAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$definition = $registry->get('query_collection');
		$this->assertNotNull($definition);
		$this->assertSame('query_collection', $definition->name);
		$this->assertSame('public', $definition->access);
	}

	public function testRegisterDeclaresDescriptionBuilderForPersonaAwareDescription(): void
	{
		// Description must vary by persona so the field catalog only shows
		// collections the caller can actually query.
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$this->assertNotNull($registry->get('query_collection')->descriptionBuilder);
	}

	public function testDescriptionBuilderComposesBaseTextWithResolverCatalog(): void
	{
		$this->resolver->method('renderCatalog')
			->with(McpPersona::PUBLIC_, McpSchemaResolver::DEFAULT_CATALOG_CAP)
			->willReturn("Available collections and filterable fields:\n- blog — title (text)");

		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$definition = $registry->get('query_collection');
		$built      = ($definition->descriptionBuilder)(McpPersona::PUBLIC_);

		// Base description text mentions the sibling tools so the AI agent learns
		// the tool topology without us instructing it (which would trip the
		// Anthropic Directory's "no behavior instructions" rule).
		$this->assertStringContainsString('search_collection', $built);
		$this->assertStringContainsString('get_object', $built);
		// Catalog is appended verbatim.
		$this->assertStringContainsString('- blog — title (text)', $built);
	}

	public function testInputSchemaRequiresNameAndCapsLimitAtFifty(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$schema = $registry->get('query_collection')->inputSchema;

		$this->assertIsArray($schema);
		$this->assertSame(['collection'], $schema['required']);
		$this->assertSame(50, $schema['properties']['limit']['maximum']);
		$this->assertSame(10, $schema['properties']['limit']['default']);
	}

	public function testInputSchemaIncludesExamplesOnFilterAndSortParams(): void
	{
		// Per the plan, every parameter description ships with JSON Schema
		// `examples` so the LLM has a literal template — reduces malformed calls.
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$properties = $registry->get('query_collection')->inputSchema['properties'];

		$this->assertNotEmpty($properties['include']['examples']);
		$this->assertNotEmpty($properties['exclude']['examples']);
		$this->assertNotEmpty($properties['sort']['examples']);
		$this->assertNotEmpty($properties['collection']['examples']);
	}

	public function testInputSchemaAcceptsFormatAndLocaleParams(): void
	{
		// format is a Chunk B placeholder (returns raw until Chunk F's
		// ContentRenderer ships); locale is forward-compat until 3.6 i18n.
		// Both must be ACCEPTED at the schema level so future versions don't
		// need a breaking change to add behavior.
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$properties = $registry->get('query_collection')->inputSchema['properties'];

		$this->assertSame(['markdown', 'html', 'text'], $properties['format']['enum']);
		$this->assertArrayHasKey('locale', $properties);
	}

	// ─── Handler: error recovery hints (B9) ───────────────────────────────────

	public function testUnknownCollectionThrowsToolCallExceptionWithRecoveryHint(): void
	{
		// MCP tool errors must come back as `isError: true` with a hint at
		// list_collections — never as a generic exception that escapes the
		// SDK transport. The SDK turns ToolCallException into the right wire
		// shape (CallToolResult::error([TextContent(...)])).
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn(null);

		try {
			$this->tool->handler(collection: 'blug');
			$this->fail('Expected ToolCallException for unknown collection.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('blug', $e->getMessage());
			$this->assertStringContainsString('list_collections', $e->getMessage());
		}
	}

	public function testCollectionInaccessibleToPersonaThrowsRecoveryHint(): void
	{
		// Public persona attempting to query an admin-only collection: must
		// fail closed with a hint, not return data.
		$this->persona->set(McpPersona::PUBLIC_);
		$this->collections->method('fetchCollection')->willReturn($this->collection('auth'));
		$this->resolver->method('isAccessibleTo')->willReturn(false);

		try {
			$this->tool->handler(collection: 'auth');
			$this->fail('Expected ToolCallException for inaccessible collection.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('auth', $e->getMessage());
			$this->assertStringContainsString('list_collections', $e->getMessage());
		}
	}

	// ─── Handler: persona safety filter (public persona → exclude draft) ─────

	public function testPublicPersonaMergesDraftTrueIntoExclude(): void
	{
		// Public callers can never see drafts. This is content moderation —
		// server-side, not caller preference. Tests the *exact wire* the
		// QueryPipeline ends up consuming so a regression in the merge would
		// produce a different params shape.
		$this->persona->set(McpPersona::PUBLIC_);
		$blog = $this->collection('blog');
		$this->collections->method('fetchCollection')->willReturn($blog);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		$this->indexQuery->expects($this->once())
			->method('query')
			->with(
				'blog',
				$this->callback(
					// Caller's exclude is preserved AND draft:true is appended.
					static fn (array $params): bool => ($params['exclude'] ?? '') === 'category:internal,draft:true'
				),
			)
			->willReturn(new QueryResult(items: [], total: 0, limit: 10, offset: 0));

		$this->tool->handler(collection: 'blog', exclude: 'category:internal');
	}

	public function testPublicPersonaAddsDraftWhenExcludeIsEmpty(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		$this->indexQuery->expects($this->once())
			->method('query')
			->with(
				'blog',
				$this->callback(static fn (array $params): bool => ($params['exclude'] ?? '') === 'draft:true'),
			)
			->willReturn(new QueryResult(items: [], total: 0, limit: 10, offset: 0));

		$this->tool->handler(collection: 'blog');
	}

	public function testAdminPersonaLeavesExcludeUntouched(): void
	{
		// Admin sees drafts — no safety filter merged. Lets admins compose
		// queries that intentionally include drafts without our middleware
		// silently filtering them out.
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		$this->indexQuery->expects($this->once())
			->method('query')
			->with(
				'blog',
				$this->callback(static fn (array $params): bool => !isset($params['exclude'])),
			)
			->willReturn(new QueryResult(items: [], total: 0, limit: 10, offset: 0));

		$this->tool->handler(collection: 'blog');
	}

	// ─── Handler: result shaping (URL decoration + field stripping) ───────────

	public function testHandlerDecoratesItemsWithUrl(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$blog = $this->collection('blog');
		$this->collections->method('fetchCollection')->willReturn($blog);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		$item = ['id' => 'hello', 'title' => 'Hello'];
		$this->indexQuery->method('query')
			->willReturn(new QueryResult(items: [$item], total: 1, limit: 10, offset: 0));
		$this->urls->method('buildUrl')->with($blog, $item)->willReturn('/blog/hello');

		$result = $this->tool->handler(collection: 'blog');

		$this->assertSame('/blog/hello', $result['items'][0]['url']);
	}

	public function testHandlerStripsNonExposedFieldsFromEveryItem(): void
	{
		// mcp.expose:false fields must never appear in tool output, even if
		// the index happens to carry them. Stripping by name (not by schema
		// re-resolution) so it's cheap per item.
		$this->persona->set(McpPersona::ADMIN);
		$blog = $this->collection('blog');
		$this->collections->method('fetchCollection')->willReturn($blog);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn(['internal_notes', 'admin_only']);

		$items = [
			['id' => 'a', 'internal_notes' => 'private', 'title' => 'A'],
			['id' => 'b', 'admin_only' => 'shh', 'title' => 'B'],
		];
		$this->indexQuery->method('query')
			->willReturn(new QueryResult(items: $items, total: 2, limit: 10, offset: 0));
		$this->urls->method('buildUrl')->willReturn('/blog/x');

		$result = $this->tool->handler(collection: 'blog');

		$this->assertArrayNotHasKey('internal_notes', $result['items'][0]);
		$this->assertArrayNotHasKey('admin_only', $result['items'][1]);
		$this->assertArrayHasKey('title', $result['items'][0]);
	}

	public function testHandlerClampsLimitAtFifty(): void
	{
		// Caller-supplied limit > 50 is clamped, not rejected — feels softer
		// than a hard error and matches the JSON Schema's `maximum: 50`.
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);

		$this->indexQuery->expects($this->once())
			->method('query')
			->with(
				'blog',
				$this->callback(static fn (array $params): bool => ($params['limit'] ?? '') === '50'),
			)
			->willReturn(new QueryResult(items: [], total: 0, limit: 50, offset: 0));

		$this->tool->handler(collection: 'blog', limit: 250);
	}

	// ─── Format param wiring (Chunk F) ────────────────────────────────────────

	public function testStyledtextPropertiesAreConvertedToMarkdownByDefault(): void
	{
		// Default format is markdown. Properties named in renderableProperties
		// have their HTML values converted to markdown before reaching the
		// agent. Properties NOT in the list (e.g. plain title) pass through
		// verbatim.
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);
		$this->resolver->method('renderableProperties')->willReturn(['content', 'summary']);

		$this->indexQuery->method('query')->willReturn(new QueryResult(
			items: [
				[
					'id'      => 'post-a',
					'title'   => 'Plain Title',
					'summary' => '<p><strong>Bold</strong> summary.</p>',
					'content' => '<p>Body with a <a href="#x">link</a>.</p>',
				],
			],
			total: 1,
			limit: 10,
			offset: 0,
		));
		$this->urls->method('buildUrl')->willReturn('/blog/post-a');

		$result = $this->tool->handler(collection: 'blog');
		$item   = $result['items'][0];

		// Plain title pass-through.
		$this->assertSame('Plain Title', $item['title']);
		// Renderable properties converted to markdown.
		$this->assertStringContainsString('**Bold** summary.', $item['summary']);
		$this->assertStringContainsString('[link](#x)', $item['content']);
	}

	public function testHtmlFormatLeavesStyledtextUnchanged(): void
	{
		// Pass-through path: the agent that wants HTML gets the storage shape
		// directly — no double conversion.
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);
		$this->resolver->method('renderableProperties')->willReturn(['content']);

		$html = '<p><strong>HTML</strong> body.</p>';
		$this->indexQuery->method('query')->willReturn(new QueryResult(
			items: [['id' => 'x', 'content' => $html]],
			total: 1,
			limit: 10,
			offset: 0,
		));
		$this->urls->method('buildUrl')->willReturn('/blog/x');

		$result = $this->tool->handler(collection: 'blog', format: 'html');

		$this->assertSame($html, $result['items'][0]['content']);
	}

	public function testTextFormatStripsTagsFromStyledtext(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);
		$this->resolver->method('renderableProperties')->willReturn(['content']);

		$this->indexQuery->method('query')->willReturn(new QueryResult(
			items: [['id' => 'x', 'content' => '<p>Plain &amp; <strong>simple</strong>.</p>']],
			total: 1,
			limit: 10,
			offset: 0,
		));
		$this->urls->method('buildUrl')->willReturn('/blog/x');

		$result = $this->tool->handler(collection: 'blog', format: 'text');

		$this->assertStringContainsString('Plain & simple.', $result['items'][0]['content']);
		$this->assertStringNotContainsString('<', $result['items'][0]['content']);
	}

	public function testHandlerReturnsPaginatedEnvelope(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);
		$this->indexQuery->method('query')
			->willReturn(new QueryResult(items: [['id' => 'x']], total: 25, limit: 10, offset: 10));
		$this->urls->method('buildUrl')->willReturn('/blog/x');

		$result = $this->tool->handler(collection: 'blog', offset: 10);

		$this->assertSame(25, $result['total']);
		$this->assertSame(10, $result['limit']);
		$this->assertSame(10, $result['offset']);
		$this->assertTrue($result['has_more']);
	}
}
