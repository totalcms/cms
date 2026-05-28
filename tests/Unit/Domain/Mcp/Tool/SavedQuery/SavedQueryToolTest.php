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
	private function makeTool(
		SavedQueryToolDefinition $definition,
		?CollectionData $collectionData = null,
		?QueryResult $queryResult = null,
	): SavedQueryTool {
		$persona = new PersonaContext();
		$persona->set(McpPersona::ADMIN);

		$indexQuery = $this->createMock(IndexQueryService::class);
		if ($queryResult !== null) {
			$indexQuery->method('query')->willReturn($queryResult);
		}

		$collectionRepo = $this->createMock(CollectionRepository::class);
		if ($collectionData !== null) {
			$collectionRepo->method('fetchCollection')->willReturn($collectionData);
		} else {
			$collectionRepo->method('fetchCollection')->willReturn(null);
		}

		$schemaResolver = $this->createMock(McpSchemaResolver::class);
		$schemaResolver->method('nonExposedProperties')->willReturn([]);
		$schemaResolver->method('renderableProperties')->willReturn([]);

		$urlBuilder = $this->createMock(ObjectUrlBuilder::class);
		$urlBuilder->method('buildUrl')->willReturn('');

		return new SavedQueryTool(
			definition: $definition,
			indexQueryService: $indexQuery,
			filterValueResolver: new FilterValueResolver(),
			contentRenderer: $this->createMock(ContentRenderer::class),
			personaContext: $persona,
			objectUrlBuilder: $urlBuilder,
			schemaResolver: $schemaResolver,
			collectionRepository: $collectionRepo,
		);
	}

	private function makePersonaContext(McpPersona $persona): PersonaContext
	{
		$ctx = new PersonaContext();
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
