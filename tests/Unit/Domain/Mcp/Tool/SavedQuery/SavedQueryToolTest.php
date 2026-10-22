<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\SavedQuery;

use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Auth\Data\ReadScope;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\CollectionQueryResultFormatter;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpDescriptionResolver;
use TotalCMS\Domain\Mcp\Service\McpObjectShaper;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Data\SavedQueryToolDefinition;
use TotalCMS\Domain\Mcp\Tool\SavedQuery\SavedQueryTool;
use TotalCMS\Domain\Mcp\Tool\Service\FilterValueResolver;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class SavedQueryToolTest extends TestCase
{
	private function makePersonaContext(McpPersona $persona): PersonaContext
	{
		// PersonaContext (Task 10b) needs CollectionFetcher + McpSchemaResolver
		// to resolve canReadCollection()'s mcp.access:'public' carve-out.
		// SavedQueryTool always passes the already-fetched CollectionData (see
		// its handle()), so the CollectionFetcher stub is never invoked. The
		// McpSchemaResolver stub returns a fixed 'public' access unconditionally
		// (rather than reading $collection->mcp, which a plain
		// createMock(CollectionData::class) — used by some tests in this file
		// — leaves uninitialized) — every test in this file uses a
		// SavedQueryToolDefinition with access:'public' or a case where this
		// check is never reached (access:'admin' denies earlier; collection-
		// missing denies earlier), so a fixed 'public' is consistent with every
		// reachable scenario here.
		$ctx = new PersonaContext($this->createStub(CollectionFetcher::class), $this->accessResolver(), mcpGuard($this), new ActorContext());
		$ctx->set($persona);

		return $ctx;
	}

	/**
	 * Resolver stub for the tool's own exposure check: every collection in this
	 * file is a plain `public` one (same reasoning as makePersonaContext()).
	 * The owned-collection test uses a real McpSchemaResolver instead.
	 */
	private function accessResolver(): McpSchemaResolver
	{
		$schemaResolver = $this->createStub(McpSchemaResolver::class);
		$schemaResolver->method('forCollection')->willReturn([
			'access' => 'public', 'owned' => false, 'description' => null, 'resource' => true, 'titleProperty' => '',
		]);
		$schemaResolver->method('isAccessibleTo')->willReturn(true);

		return $schemaResolver;
	}

	public function testThrowsToolCallExceptionWhenPersonaBelowCollectionAccess(): void
	{
		// collection.mcp.access = admin; current persona = public
		$def = SavedQueryToolDefinition::fromArray('listings', 'admin', [
			'name'        => 'admin_query',
			'description' => 'Admin only.',
		]);

		$personaCtx = $this->makePersonaContext(McpPersona::PUBLIC_);

		$tool = new SavedQueryTool(
			definition: $def,
			indexQueryService: $this->createMock(IndexQueryService::class),
			filterValueResolver: new FilterValueResolver(),
			personaContext: $personaCtx,
			shaper: new McpObjectShaper($this->createMock(McpSchemaResolver::class), $this->createMock(ContentRenderer::class), $this->createMock(ObjectUrlBuilder::class)),
			collectionRepository: $this->createMock(CollectionRepository::class),
			resultFormatter: new CollectionQueryResultFormatter(),
			schemaResolver: $this->accessResolver(),
		);

		try {
			$tool->handle([]);
			$this->fail('Expected ToolCallException.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('admin access', $e->getMessage());
		}
	}

	public function testSubstitutesFilterPlaceholdersBeforeQueryDispatch(): void
	{
		$def = SavedQueryToolDefinition::fromArray('listings', 'public', [
			'name'        => 'find_by_city',
			'description' => 'Find listings by city.',
			'params'      => [
				'city' => ['type' => 'string', 'required' => true],
			],
			'filters' => [
				'city' => ['operator' => 'eq', 'value' => '{{params.city}}'],
			],
		]);

		$collection = $this->createMock(CollectionData::class);

		$capturedParams = null;
		$indexQuery     = $this->createMock(IndexQueryService::class);
		$indexQuery->method('query')->willReturnCallback(
			function (string $coll, array $params) use (&$capturedParams): QueryResult {
				$capturedParams = $params;

				return new QueryResult(items: [], total: 0, limit: 20, offset: 0);
			},
		);

		$collectionRepo = $this->createMock(CollectionRepository::class);
		$collectionRepo->method('fetchCollection')->willReturn($collection);

		$schemaResolver = $this->createMock(McpSchemaResolver::class);
		$schemaResolver->method('nonExposedProperties')->willReturn([]);
		$schemaResolver->method('renderableProperties')->willReturn([]);

		$personaCtx = $this->makePersonaContext(McpPersona::PUBLIC_);

		$tool = new SavedQueryTool(
			definition: $def,
			indexQueryService: $indexQuery,
			filterValueResolver: new FilterValueResolver(),
			personaContext: $personaCtx,
			shaper: new McpObjectShaper($schemaResolver, $this->createMock(ContentRenderer::class), $this->createMock(ObjectUrlBuilder::class)),
			collectionRepository: $collectionRepo,
			resultFormatter: new CollectionQueryResultFormatter(),
			schemaResolver: $this->accessResolver(),
		);

		$result = $tool->handle(['city' => 'Austin']);

		// Shared envelope {items, total, limit, offset, has_more} — no
		// hand-built `content` wrapper. The SDK builds content[0].text /
		// structuredContent from this raw return value (see SavedQueryTool's
		// class docblock and CollectionQueryResultFormatter).
		$this->assertSame(['items', 'total', 'limit', 'offset', 'has_more'], array_keys($result));
		$this->assertSame(0, $result['total']);
		$this->assertSame(20, $result['limit']);
		$this->assertSame(0, $result['offset']);
		$this->assertFalse($result['has_more']);
		// The include param should contain the resolved city filter.
		$this->assertStringContainsString('Austin', $capturedParams['include'] ?? '');
	}

	public function testAnUnrecognisedAccessLevelStillAdmitsTheAdmin(): void
	{
		// The access gate must fail closed to admin-only, not to nobody. This
		// tool used to be the one copy of the persona rule whose fallback
		// denied everyone — so a malformed mcp.access locked the admin out of
		// their own saved query. Passing the gate here lands on the missing
		// collection, whose error names list_collections.
		$def = SavedQueryToolDefinition::fromArray('does-not-exist', 'bogus', [
			'name'        => 'odd_tool',
			'description' => 'Odd access value.',
		]);

		$tool = new SavedQueryTool(
			definition: $def,
			indexQueryService: $this->createMock(IndexQueryService::class),
			filterValueResolver: new FilterValueResolver(),
			personaContext: $this->makePersonaContext(McpPersona::ADMIN),
			shaper: new McpObjectShaper($this->createMock(McpSchemaResolver::class), $this->createMock(ContentRenderer::class), $this->createMock(ObjectUrlBuilder::class)),
			collectionRepository: $this->createMock(CollectionRepository::class),
			resultFormatter: new CollectionQueryResultFormatter(),
			schemaResolver: $this->accessResolver(),
		);

		try {
			$tool->handle([]);
			$this->fail('Expected ToolCallException.');
		} catch (ToolCallException $e) {
			$this->assertStringNotContainsString('requires admin access', $e->getMessage());
			$this->assertStringContainsString('list_collections', $e->getMessage());
		}
	}

	public function testThrowsToolCallExceptionWithRecoveryHintWhenCollectionMissing(): void
	{
		$def = SavedQueryToolDefinition::fromArray('does-not-exist', 'admin', [
			'name'        => 'orphan_tool',
			'description' => 'Orphan.',
		]);

		$personaCtx = $this->makePersonaContext(McpPersona::ADMIN);

		$tool = new SavedQueryTool(
			definition: $def,
			indexQueryService: $this->createMock(IndexQueryService::class),
			filterValueResolver: new FilterValueResolver(),
			personaContext: $personaCtx,
			shaper: new McpObjectShaper($this->createMock(McpSchemaResolver::class), $this->createMock(ContentRenderer::class), $this->createMock(ObjectUrlBuilder::class)),
			collectionRepository: $this->createMock(CollectionRepository::class),
			resultFormatter: new CollectionQueryResultFormatter(),
			schemaResolver: $this->accessResolver(),
		);

		try {
			$tool->handle([]);
			$this->fail('Expected ToolCallException.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('list_collections', $e->getMessage());
		}
	}

	// ──────────────────────────────────────────────────────────────────────
	// Wire-shape regression coverage — dispatches through the REAL mcp/sdk
	// classes (Registry, ReferenceHandler, CallToolHandler), not just
	// SavedQueryTool::handle() in isolation. This is what actually proves
	// the fix: bug 1 was in how the SDK's extractStructuredContent()/
	// ToolResultFormatter treat SavedQueryTool's return value, and bug 2 was
	// in how CallToolHandler decides CallToolResult.isError — neither is
	// observable by calling handle() directly.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Dispatches a tools/call for $toolName through the real SDK request
	 * handler, exactly as McpEndpointAction does (minus the HTTP/session
	 * transport layer).
	 */
	private function dispatchToolCall(string $toolName, \Closure $handler): Response|Error
	{
		$registry = new Registry();
		$registry->registerTool(
			new Tool(
				name: $toolName,
				title: null,
				inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
				description: 'Test tool.',
				annotations: null,
			),
			$handler,
		);

		$handlerChain = new CallToolHandler($registry, new ReferenceHandler());
		$session      = new Session(new InMemorySessionStore());
		$request      = (new CallToolRequest($toolName, []))->withId(1);

		return $handlerChain->handle($request, $session);
	}

	public function testSdkDispatchReturnsCleanStructuredContentOnSuccessNotDoubleWrapped(): void
	{
		$def = SavedQueryToolDefinition::fromArray('listings', 'public', [
			'name'        => 'find_listings',
			'description' => 'Find listings.',
		]);

		$collection = $this->createMock(CollectionData::class);

		$indexQuery = $this->createMock(IndexQueryService::class);
		$indexQuery->method('query')->willReturn(
			new QueryResult(items: [['id' => 'austin-1', 'city' => 'Austin']], total: 1, limit: 20, offset: 0),
		);

		$collectionRepo = $this->createMock(CollectionRepository::class);
		$collectionRepo->method('fetchCollection')->willReturn($collection);

		$schemaResolver = $this->createMock(McpSchemaResolver::class);
		$schemaResolver->method('nonExposedProperties')->willReturn([]);
		$schemaResolver->method('renderableProperties')->willReturn([]);

		$personaCtx = $this->makePersonaContext(McpPersona::PUBLIC_);

		$tool = new SavedQueryTool(
			definition: $def,
			indexQueryService: $indexQuery,
			filterValueResolver: new FilterValueResolver(),
			personaContext: $personaCtx,
			shaper: new McpObjectShaper($schemaResolver, $this->createMock(ContentRenderer::class), $this->createMock(ObjectUrlBuilder::class)),
			collectionRepository: $collectionRepo,
			resultFormatter: new CollectionQueryResultFormatter(),
			schemaResolver: $this->accessResolver(),
		);

		$response = $this->dispatchToolCall('find_listings', fn (): array => $tool->handle([]));

		$this->assertInstanceOf(Response::class, $response);
		$result = $response->result;
		$this->assertInstanceOf(CallToolResult::class, $result);
		$this->assertFalse($result->isError);

		// Shared envelope: structuredContent must be the bare
		// {items, total, limit, offset, has_more} payload — NOT
		// {content: [{type, text}]}, and NOT the old {items, count} shape.
		$this->assertSame(
			['items', 'total', 'limit', 'offset', 'has_more'],
			array_keys($result->structuredContent ?? []),
		);
		$this->assertCount(1, $result->structuredContent['items']);
		$this->assertSame('austin-1', $result->structuredContent['items'][0]['id']);
		$this->assertSame(1, $result->structuredContent['total']);
		$this->assertSame(20, $result->structuredContent['limit']);
		$this->assertSame(0, $result->structuredContent['offset']);
		$this->assertFalse($result->structuredContent['has_more']);

		// content[0].text must still carry the JSON for clients that read it
		// instead of structuredContent.
		$this->assertCount(1, $result->content);
		$textContent = $result->content[0];
		$this->assertInstanceOf(TextContent::class, $textContent);
		$decoded = json_decode($textContent->text, true);
		$this->assertSame(['items', 'total', 'limit', 'offset', 'has_more'], array_keys($decoded));
		$this->assertSame('austin-1', $decoded['items'][0]['id']);
		$this->assertSame(1, $decoded['total']);
	}

	public function testSdkDispatchReportsHasMoreTrueWhenResultIsTruncated(): void
	{
		// This is the case that actually proves the fix's value: the old
		// {items, count} shape could never distinguish "here are all 2
		// matching items" from "here are 2 of 5 — call again for the rest".
		// The tool definition's own limit (2) is lower than the total number
		// of matching records (5), so the QueryResult the SDK gets back is
		// genuinely truncated.
		$def = SavedQueryToolDefinition::fromArray('listings', 'public', [
			'name'        => 'find_listings',
			'description' => 'Find listings.',
			'limit'       => 2,
		]);

		$collection = $this->createMock(CollectionData::class);

		$indexQuery = $this->createMock(IndexQueryService::class);
		$indexQuery->method('query')->willReturn(
			new QueryResult(
				items: [
					['id' => 'listing-1', 'city' => 'Austin'],
					['id' => 'listing-2', 'city' => 'Austin'],
				],
				total: 5,
				limit: 2,
				offset: 0,
			),
		);

		$collectionRepo = $this->createMock(CollectionRepository::class);
		$collectionRepo->method('fetchCollection')->willReturn($collection);

		$schemaResolver = $this->createMock(McpSchemaResolver::class);
		$schemaResolver->method('nonExposedProperties')->willReturn([]);
		$schemaResolver->method('renderableProperties')->willReturn([]);

		$personaCtx = $this->makePersonaContext(McpPersona::PUBLIC_);

		$tool = new SavedQueryTool(
			definition: $def,
			indexQueryService: $indexQuery,
			filterValueResolver: new FilterValueResolver(),
			personaContext: $personaCtx,
			shaper: new McpObjectShaper($schemaResolver, $this->createMock(ContentRenderer::class), $this->createMock(ObjectUrlBuilder::class)),
			collectionRepository: $collectionRepo,
			resultFormatter: new CollectionQueryResultFormatter(),
			schemaResolver: $this->accessResolver(),
		);

		$response = $this->dispatchToolCall('find_listings', fn (): array => $tool->handle([]));

		$this->assertInstanceOf(Response::class, $response);
		$result = $response->result;
		$this->assertInstanceOf(CallToolResult::class, $result);
		$this->assertFalse($result->isError);

		$structured = $result->structuredContent ?? [];
		$this->assertCount(2, $structured['items']);
		$this->assertSame(5, $structured['total']);
		$this->assertSame(2, $structured['limit']);
		$this->assertSame(0, $structured['offset']);
		// The value that {items, count} could never express: this response
		// is 2 of 5, not "all of them".
		$this->assertTrue($structured['has_more']);

		$decoded = json_decode((string)$result->content[0]->text, true);
		$this->assertTrue($decoded['has_more']);
		$this->assertSame(5, $decoded['total']);
	}

	public function testSdkDispatchSetsOuterIsErrorTrueOnFailure(): void
	{
		// collection.mcp.access = admin; current persona = public — deterministic
		// failure with no domain collaborators to configure.
		$def = SavedQueryToolDefinition::fromArray('listings', 'admin', [
			'name'        => 'admin_query',
			'description' => 'Admin only.',
		]);

		$personaCtx = $this->makePersonaContext(McpPersona::PUBLIC_);

		$tool = new SavedQueryTool(
			definition: $def,
			indexQueryService: $this->createMock(IndexQueryService::class),
			filterValueResolver: new FilterValueResolver(),
			personaContext: $personaCtx,
			shaper: new McpObjectShaper($this->createMock(McpSchemaResolver::class), $this->createMock(ContentRenderer::class), $this->createMock(ObjectUrlBuilder::class)),
			collectionRepository: $this->createMock(CollectionRepository::class),
			resultFormatter: new CollectionQueryResultFormatter(),
			schemaResolver: $this->accessResolver(),
		);

		$response = $this->dispatchToolCall('admin_query', fn (): array => $tool->handle([]));

		// Bug 2: CallToolHandler catches ToolCallException and builds a
		// CallToolResult with isError:true — a hand-built ['isError' => true]
		// array return would NOT reach here (it never throws, so
		// CallToolHandler would build a success CallToolResult instead).
		$this->assertInstanceOf(Response::class, $response);
		$result = $response->result;
		$this->assertInstanceOf(CallToolResult::class, $result);
		$this->assertTrue($result->isError);
		$this->assertStringContainsString('admin access', $result->content[0]->text);
	}

	/**
	 * A saved-query tool on an owned collection, run over a REAL
	 * McpSchemaResolver and a PersonaContext whose authority grants read on
	 * it, for member `member-1`. Returns the read scope the index query
	 * received, or the refusal.
	 */
	private function runOwnedTool(McpPersona $persona, string $access): ReadScope|ToolCallException
	{
		$def = SavedQueryToolDefinition::fromArray('portfolio', $access, [
			'name'        => 'find_all',
			'description' => 'All.',
		]);

		$portfolio                = new CollectionData();
		$portfolio->id            = 'portfolio';
		$portfolio->schema        = 'portfolio';
		$portfolio->mcp           = ['access' => $access];
		$portfolio->ownerProperty = 'owner';

		$collectionRepo = $this->createStub(CollectionRepository::class);
		$collectionRepo->method('fetchCollection')->willReturn($portfolio);
		$fetcher = $this->createStub(CollectionFetcher::class);
		$fetcher->method('fetchCollection')->willReturn($portfolio);

		$resolver = new McpSchemaResolver($this->createStub(SchemaFetcher::class), new McpDescriptionResolver(), $collectionRepo);
		$authority = new UserAuthority(isAdmin: false, groups: [new AccessGroupData([
			'id'          => 'members',
			'permissions' => ['collections' => ['operations' => ['read'], 'all' => false, 'allowed' => ['portfolio']]],
		])]);
		$actors = $persona === McpPersona::PUBLIC_ ? new ActorContext() : memberActors($authority);
		$context = new PersonaContext($fetcher, $resolver, mcpGuard($this, ['portfolio' => 'owner']), $actors);
		$context->set($persona);
		$context->setAuthority($authority);

		$captured   = null;
		$indexQuery = $this->createStub(IndexQueryService::class);
		$indexQuery->method('query')->willReturnCallback(
			function (string $coll, array $params, ?ReadScope $scope = null) use (&$captured): QueryResult {
				$captured = $scope;

				return new QueryResult(items: [], total: 0, limit: 20, offset: 0);
			},
		);

		$tool = new SavedQueryTool(
			definition: $def,
			indexQueryService: $indexQuery,
			filterValueResolver: new FilterValueResolver(),
			personaContext: $context,
			shaper: new McpObjectShaper($this->createStub(McpSchemaResolver::class), $this->createStub(ContentRenderer::class), $this->createStub(ObjectUrlBuilder::class)),
			collectionRepository: $collectionRepo,
			resultFormatter: new CollectionQueryResultFormatter(),
			schemaResolver: $resolver,
		);

		try {
			$tool->handle([]);
		} catch (ToolCallException $e) {
			return $e;
		}

		$this->assertInstanceOf(ReadScope::class, $captured);

		return $captured;
	}

	public function testAnAnonymousCallerOnAnOwnedPublicCollectionSeesNoDrafts(): void
	{
		$scope = $this->runOwnedTool(McpPersona::PUBLIC_, 'public');

		$this->assertInstanceOf(ReadScope::class, $scope);
		$this->assertFalse($scope->seesDrafts());
	}

	public function testAMemberOnAnOwnedPublicCollectionSeesTheirOwnDraftsAndOthersPublished(): void
	{
		$scope = $this->runOwnedTool(McpPersona::AUTHENTICATED, 'public');

		$this->assertInstanceOf(ReadScope::class, $scope);
		$this->assertTrue($scope->matches(['owner' => 'member-1', 'draft' => true]));
		$this->assertTrue($scope->matches(['owner' => 'sam', 'draft' => false]));
		$this->assertFalse($scope->matches(['owner' => 'sam', 'draft' => true]));
	}

	public function testAMemberOnAnOwnedCollectionThatIsNotPublicSeesOnlyTheirOwn(): void
	{
		$scope = $this->runOwnedTool(McpPersona::AUTHENTICATED, 'authenticated');

		$this->assertInstanceOf(ReadScope::class, $scope);
		$this->assertTrue($scope->matches(['owner' => 'member-1', 'draft' => true]));
		$this->assertFalse($scope->matches(['owner' => 'sam', 'draft' => false]));
	}

	public function testAdminSeesDraftsOnAnOwnedCollection(): void
	{
		$scope = $this->runOwnedTool(McpPersona::ADMIN, 'authenticated');

		$this->assertInstanceOf(ReadScope::class, $scope);
		$this->assertTrue($scope->isAll());
	}
}
