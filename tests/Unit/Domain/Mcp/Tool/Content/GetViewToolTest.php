<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Content\GetViewTool;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

final class GetViewToolTest extends TestCase
{
	/** @var MockObject&DataViewFetcher */
	private MockObject $fetcher;
	/** @var MockObject&ObjectFetcher */
	private MockObject $objects;
	private PersonaContext $persona;
	private GetViewTool $tool;

	protected function setUp(): void
	{
		$this->fetcher = $this->createMock(DataViewFetcher::class);
		$this->objects = $this->createMock(ObjectFetcher::class);
		$this->persona = new PersonaContext();
		$this->tool    = new GetViewTool($this->fetcher, $this->objects, $this->persona);
	}

	/**
	 * Mock an ObjectData that stubs toArray() to return the given dict.
	 * Building real ObjectData requires PropertyData wrappers per field;
	 * the mock keeps the test focused on tool behaviour, not data plumbing.
	 *
	 * @param array<string,mixed> $props
	 */
	private function viewObject(array $props): ObjectData
	{
		/** @var MockObject&ObjectData $obj */
		$obj = $this->createMock(ObjectData::class);
		$obj->method('toArray')->willReturn($props);

		return $obj;
	}

	// ── Registration ─────────────────────────────────────────────────────────

	public function testRegisterAddsToolWithExpectedShape(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$tool = $registry->get('get_view');
		$this->assertNotNull($tool);
		$this->assertSame('public', $tool->access);
		$this->assertNotNull($tool->outputSchema);
	}

	// ── Persona enforcement ──────────────────────────────────────────────────

	public function testPublicCannotFetchAdminView(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject(['id' => 'admin-only', 'mcp' => ['access' => 'admin']])
		);

		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessageMatches('/not accessible/i');

		$this->tool->handler(id: 'admin-only');
	}

	public function testPublicCanFetchPublicView(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject(['id' => 'pub', 'mcp' => ['access' => 'public']])
		);
		$this->fetcher->method('getViewData')->willReturn([
			['name' => 'A'], ['name' => 'B'],
		]);

		$result = $this->tool->handler(id: 'pub');

		$this->assertSame(2, $result['total']);
		$this->assertFalse($result['truncated']);
		$this->assertSame([['name' => 'A'], ['name' => 'B']], $result['items']);
	}

	public function testMissingViewThrowsWithRecoveryHint(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willThrowException(new \RuntimeException('not found'));

		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessageMatches('/list_views/');

		$this->tool->handler(id: 'ghost');
	}

	// ── Cap at 50 ────────────────────────────────────────────────────────────

	public function testCapsAtFiftyItemsAndEmitsHint(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject(['id' => 'big', 'mcp' => ['access' => 'public']])
		);

		// 75 items in the cached data; expect items capped at 50.
		$items = [];
		for ($i = 1; $i <= 75; $i++) {
			$items[] = ['n' => $i];
		}
		$this->fetcher->method('getViewData')->willReturn($items);

		$result = $this->tool->handler(id: 'big');

		$this->assertCount(50, $result['items']);
		$this->assertSame(75, $result['total']);
		$this->assertTrue($result['truncated']);
		$this->assertArrayHasKey('hint', $result);
		$this->assertStringContainsString('query_view', $result['hint']);
	}

	public function testExactlyFiftyItemsDoesNotEmitHint(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject(['id' => 'fifty', 'mcp' => ['access' => 'public']])
		);
		$items = [];
		for ($i = 1; $i <= 50; $i++) {
			$items[] = ['n' => $i];
		}
		$this->fetcher->method('getViewData')->willReturn($items);

		$result = $this->tool->handler(id: 'fifty');

		$this->assertFalse($result['truncated']);
		$this->assertArrayNotHasKey('hint', $result);
	}
}
