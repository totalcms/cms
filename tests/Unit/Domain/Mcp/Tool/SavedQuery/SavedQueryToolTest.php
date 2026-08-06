<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\SavedQuery;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Data\SavedQueryToolDefinition;
use TotalCMS\Domain\Mcp\Tool\SavedQuery\SavedQueryTool;
use TotalCMS\Domain\Mcp\Tool\Service\FilterValueResolver;
use TotalCMS\Domain\Query\Data\QueryResult;

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
		$schemaResolver = $this->createStub(McpSchemaResolver::class);
		$schemaResolver->method('forCollection')->willReturn([
			'access' => 'public', 'description' => null, 'resource' => true, 'titleProperty' => '',
		]);

		$ctx = new PersonaContext($this->createStub(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class), $schemaResolver);
		$ctx->set($persona);

		return $ctx;
	}

	public function testDenieDispatchWhenPersonaBelowCollectionAccess(): void
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
			contentRenderer: $this->createMock(ContentRenderer::class),
			personaContext: $personaCtx,
			objectUrlBuilder: $this->createMock(ObjectUrlBuilder::class),
			schemaResolver: $this->createMock(McpSchemaResolver::class),
			collectionRepository: $this->createMock(CollectionRepository::class),
		);

		$result = $tool->handle([]);

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('admin access', $result['content'][0]['text']);
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
			contentRenderer: $this->createMock(ContentRenderer::class),
			personaContext: $personaCtx,
			objectUrlBuilder: $this->createMock(ObjectUrlBuilder::class),
			schemaResolver: $schemaResolver,
			collectionRepository: $collectionRepo,
		);

		$result = $tool->handle(['city' => 'Austin']);

		$this->assertFalse($result['isError'] ?? false);
		$this->assertNotEmpty($result['content']);
		// The include param should contain the resolved city filter.
		$this->assertStringContainsString('Austin', $capturedParams['include'] ?? '');
	}

	public function testReturnsIsErrorWithRecoveryHintWhenCollectionMissing(): void
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
			contentRenderer: $this->createMock(ContentRenderer::class),
			personaContext: $personaCtx,
			objectUrlBuilder: $this->createMock(ObjectUrlBuilder::class),
			schemaResolver: $this->createMock(McpSchemaResolver::class),
			collectionRepository: $this->createMock(CollectionRepository::class),
		);

		$result = $tool->handle([]);

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('list_collections', $result['content'][0]['text']);
	}
}
