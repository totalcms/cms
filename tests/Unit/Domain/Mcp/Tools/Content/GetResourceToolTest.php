<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tools\Content;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;
use TotalCMS\Domain\Mcp\Tools\Content\GetObjectTool;
use TotalCMS\Domain\Mcp\Tools\Content\GetResourceTool;

final class GetResourceToolTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $getObject;
	private GetResourceTool $tool;

	protected function setUp(): void
	{
		$this->getObject = $this->createMock(GetObjectTool::class);
		$this->tool      = new GetResourceTool($this->getObject);
	}

	// ─── Registration ────────────────────────────────────────────────────────

	public function testRegisterAddsToolWithExpectedNameAndAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$definition = $registry->get('get_resource');
		$this->assertNotNull($definition);
		$this->assertSame('public', $definition->access);
	}

	public function testInputSchemaRequiresUri(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$schema = $registry->get('get_resource')->inputSchema;

		$this->assertSame(['uri'], $schema['required']);
		$this->assertNotEmpty($schema['properties']['uri']['examples']);
	}

	public function testDescriptionDocumentsTcmsScheme(): void
	{
		// Stub tool: agent needs to know the URI shape to construct one. The
		// description carries the format spec; examples in the schema give it
		// literal templates to copy from.
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$description = $registry->get('get_resource')->description;

		$this->assertStringContainsString('tcms://', $description);
		$this->assertStringContainsString('get_object', $description);
	}

	// ─── URI parsing ─────────────────────────────────────────────────────────

	public function testValidTcmsUriDispatchesToGetObjectWithParsedArgs(): void
	{
		// The whole point: tcms://blog/hello-world → GetObjectTool::handler(
		// collection: 'blog', id: 'hello-world'). Identity passthrough on the
		// returned shape — get_resource is just a routing layer.
		$expected = ['id' => 'hello-world', 'title' => 'Hello'];
		$this->getObject->expects($this->once())
			->method('handler')
			->with(collection: 'blog', id: 'hello-world')
			->willReturn($expected);

		$result = $this->tool->handler(uri: 'tcms://blog/hello-world');

		$this->assertSame($expected, $result);
	}

	public function testIdMayContainHyphensAndSlashes(): void
	{
		// Object ids are slugs (kebab-case is the norm). Hyphens are common.
		// Any extra path segments still join as part of the id — gives Phase 2
		// room to introduce nested resource hierarchies later without a
		// breaking change to this tool.
		$this->getObject->expects($this->once())
			->method('handler')
			->with(collection: 'blog', id: 'multi-part-slug-with-hyphens')
			->willReturn(['id' => 'multi-part-slug-with-hyphens']);

		$this->tool->handler(uri: 'tcms://blog/multi-part-slug-with-hyphens');
	}

	// ─── Validation errors ───────────────────────────────────────────────────

	public function testNonTcmsSchemeThrowsRecoveryHint(): void
	{
		// Don't accept http:// or any other scheme — keeps the URI namespace
		// stable for Phase 2's resource-subscribe expansion.
		try {
			$this->tool->handler(uri: 'http://blog/hello');
			$this->fail('Expected ToolCallException for non-tcms scheme.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('tcms://', $e->getMessage());
		}
	}

	public function testMissingCollectionThrowsRecoveryHint(): void
	{
		try {
			$this->tool->handler(uri: 'tcms://');
			$this->fail('Expected ToolCallException for missing collection.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('list_collections', $e->getMessage());
		}
	}

	public function testMissingObjectIdThrowsRecoveryHint(): void
	{
		// `tcms://blog` alone with no path → no id. Point at query_collection
		// since that's how the agent should discover ids in the first place.
		try {
			$this->tool->handler(uri: 'tcms://blog');
			$this->fail('Expected ToolCallException for missing id.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('query_collection', $e->getMessage());
		}
	}

	public function testMalformedUriThrowsRecoveryHint(): void
	{
		try {
			$this->tool->handler(uri: 'not even a uri');
			$this->fail('Expected ToolCallException for malformed URI.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('tcms://', $e->getMessage());
		}
	}
}
