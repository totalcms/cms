<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\DataView\Service\DataViewQueryService;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Content\QueryViewTool;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Query\Data\QueryResult;

final class QueryViewToolTest extends TestCase
{
	/** @var MockObject&DataViewQueryService */
	private MockObject $queryService;
	/** @var MockObject&ObjectFetcher */
	private MockObject $objects;
	private PersonaContext $persona;
	private QueryViewTool $tool;

	protected function setUp(): void
	{
		$this->queryService = $this->createMock(DataViewQueryService::class);
		$this->objects      = $this->createMock(ObjectFetcher::class);
		$this->persona      = new PersonaContext();
		$this->tool         = new QueryViewTool($this->queryService, $this->objects, $this->persona);
	}

	/** @param array<string,mixed> $props */
	private function viewObject(array $props): ObjectData
	{
		/** @var MockObject&ObjectData $obj */
		$obj = $this->createMock(ObjectData::class);
		$obj->method('toArray')->willReturn($props);

		return $obj;
	}

	public function testRegisterAddsToolWithExpectedShape(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$tool = $registry->get('query_view');
		$this->assertNotNull($tool);
		$this->assertSame('public', $tool->access);
		$this->assertNotNull($tool->outputSchema);
	}

	public function testPublicCannotQueryAdminView(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject(['id' => 'admin', 'mcp' => ['access' => 'admin']])
		);

		$this->expectException(ToolCallException::class);
		$this->tool->handler(id: 'admin');
	}

	public function testHappyPathDelegatesToQueryServiceWithRestSyntaxParams(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject(['id' => 'recent', 'mcp' => ['access' => 'public']])
		);

		$this->queryService->expects($this->once())
			->method('query')
			->with(
				'recent',
				$this->callback(static function (array $params): bool {
					return ($params['include'] ?? '') === 'featured:true'
						&& ($params['exclude'] ?? '') === 'draft:true'
						&& ($params['sort']    ?? '') === 'date:desc'
						&& $params['limit']  === '5'
						&& $params['offset'] === '10';
				}),
			)
			->willReturn(new QueryResult(items: [['a' => 1]], total: 1, limit: 5, offset: 10));

		$result = $this->tool->handler(
			id:      'recent',
			include: 'featured:true',
			exclude: 'draft:true',
			sort:    'date:desc',
			limit:   5,
			offset:  10,
		);

		$this->assertSame(1, $result['total']);
		$this->assertSame(5, $result['limit']);
		$this->assertSame(10, $result['offset']);
		$this->assertFalse($result['has_more']);
	}

	public function testLimitCapsAtFifty(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject(['id' => 'big', 'mcp' => ['access' => 'public']])
		);

		$this->queryService->expects($this->once())
			->method('query')
			->with('big', $this->callback(static fn (array $p): bool => $p['limit'] === '50'))
			->willReturn(new QueryResult(items: [], total: 0, limit: 50, offset: 0));

		// Request 9999 → server clamps to 50 before hitting the QueryService.
		$this->tool->handler(id: 'big', limit: 9999);
	}

	public function testHasMorePropagatesFromQueryResult(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject(['id' => 'big', 'mcp' => ['access' => 'public']])
		);
		$this->queryService->method('query')->willReturn(
			new QueryResult(items: [['a' => 1]], total: 100, limit: 10, offset: 0)
		);

		$result = $this->tool->handler(id: 'big', limit: 10);
		$this->assertTrue($result['has_more']);
	}

	public function testMissingViewThrowsWithListViewsHint(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willThrowException(new \RuntimeException('not found'));

		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessageMatches('/list_views/');

		$this->tool->handler(id: 'ghost');
	}
}
