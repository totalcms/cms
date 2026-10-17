<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Discovery;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Discovery\DescribeViewTool;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

final class DescribeViewToolTest extends TestCase
{
	/** @var MockObject&DataViewFetcher */
	private MockObject $fetcher;
	/** @var MockObject&ObjectFetcher */
	private MockObject $objects;
	private PersonaContext $persona;
	private DescribeViewTool $tool;

	protected function setUp(): void
	{
		$this->fetcher = $this->createMock(DataViewFetcher::class);
		$this->objects = $this->createMock(ObjectFetcher::class);
		// DescribeViewTool never touches canReadCollection()/canReadDrafts() —
		// PersonaContext's Task 10b constructor deps are unused here; plain
		// stubs satisfy the type only.
		$this->persona = new PersonaContext($this->createStub(CollectionFetcher::class), $this->createStub(McpSchemaResolver::class));
		$this->tool    = new DescribeViewTool($this->fetcher, $this->objects, $this->persona);
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

		$tool = $registry->get('describe_view');
		$this->assertNotNull($tool);
		$this->assertSame('public', $tool->access);
		$this->assertNotNull($tool->outputSchema);
	}

	public function testReturnsMetadataAndOutputShapeFromFirstSampledItem(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject([
				'id'          => 'recent',
				'name'        => 'Recent Posts',
				'description' => 'Last 10',
				'lastBuilt'   => '2026-05-01 10:00:00',
				'mcp'         => ['access' => 'public', 'description' => 'mcp text'],
				'definition'  => '{% set data = ... %}',
			])
		);
		$this->fetcher->method('getViewData')->willReturn([
			['title' => 'Hi', 'slug' => 'hi', 'date' => '2026-05-01'],
			['title' => 'Two'],
		]);

		$result = $this->tool->handler(id: 'recent');

		$this->assertSame('recent', $result['id']);
		$this->assertSame('Recent Posts', $result['name']);
		$this->assertSame('mcp text', $result['description']); // mcp.description wins over view.description
		$this->assertSame('2026-05-01 10:00:00', $result['last_built']);
		$this->assertSame('public', $result['access']);
		$this->assertSame(2, $result['total_items']);
		$this->assertSame(['title', 'slug', 'date'], $result['output_shape']);
	}

	public function testDescriptionFallsBackToObjectDescriptionWhenMcpAbsent(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject([
				'id'          => 'r',
				'description' => 'plain view description',
				'mcp'         => ['access' => 'public'],
			])
		);
		$this->fetcher->method('getViewData')->willReturn([]);

		$result = $this->tool->handler(id: 'r');
		$this->assertSame('plain view description', $result['description']);
	}

	public function testAdminPersonaIncludesDefinitionField(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject([
				'id'         => 'r',
				'mcp'        => ['access' => 'public'],
				'definition' => '{% set data = ... %}',
			])
		);
		$this->fetcher->method('getViewData')->willReturn([]);

		$result = $this->tool->handler(id: 'r');

		$this->assertArrayHasKey('definition', $result);
		$this->assertSame('{% set data = ... %}', $result['definition']);
	}

	public function testPublicPersonaOmitsDefinitionField(): void
	{
		// Definition may leak field names the view chose not to expose in its
		// output — public callers never see it.
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject([
				'id'         => 'r',
				'mcp'        => ['access' => 'public'],
				'definition' => '{% secret-leaking-twig %}',
			])
		);
		$this->fetcher->method('getViewData')->willReturn([]);

		$result = $this->tool->handler(id: 'r');

		$this->assertArrayNotHasKey('definition', $result);
	}

	public function testPublicCannotDescribeAdminView(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject(['id' => 'r', 'mcp' => ['access' => 'admin']])
		);

		$this->expectException(ToolCallException::class);
		$this->tool->handler(id: 'r');
	}

	public function testEmptyDataProducesEmptyOutputShape(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->objects->method('fetchObject')->willReturn(
			$this->viewObject(['id' => 'never-built', 'mcp' => ['access' => 'public']])
		);
		$this->fetcher->method('getViewData')->willReturn([]);

		$result = $this->tool->handler(id: 'never-built');

		$this->assertSame([], $result['output_shape']);
		$this->assertSame(0, $result['total_items']);
	}
}
