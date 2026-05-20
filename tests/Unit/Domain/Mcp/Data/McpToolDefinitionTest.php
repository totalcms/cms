<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Data\McpToolDefinition;

final class McpToolDefinitionTest extends TestCase
{
	private function tool(string $access): McpToolDefinition
	{
		return new McpToolDefinition(
			name: 'example_tool',
			description: 'Example',
			access: $access,
			handler: static fn (): array => [],
			inputSchema: null,
		);
	}

	public function testConstructorAssignsAllProperties(): void
	{
		$handler = static fn (): array => ['ok' => true];
		$schema  = ['type' => 'object', 'properties' => new \stdClass()];

		$tool = new McpToolDefinition('my_tool', 'desc', 'admin', $handler, $schema);

		$this->assertSame('my_tool', $tool->name);
		$this->assertSame('desc', $tool->description);
		$this->assertSame('admin', $tool->access);
		$this->assertSame($handler, $tool->handler);
		$this->assertSame($schema, $tool->inputSchema);
	}

	public function testInputSchemaDefaultsToNull(): void
	{
		$tool = new McpToolDefinition('t', 'd', 'public', static fn () => null);

		$this->assertNull($tool->inputSchema);
	}

	public function testAdminPersonaSeesAdminTool(): void
	{
		$this->assertTrue($this->tool('admin')->isVisibleTo(McpPersona::ADMIN));
	}

	public function testAdminPersonaSeesPublicTool(): void
	{
		$this->assertTrue($this->tool('public')->isVisibleTo(McpPersona::ADMIN));
	}

	public function testAdminPersonaSeesAuthenticatedTool(): void
	{
		$this->assertTrue($this->tool('authenticated')->isVisibleTo(McpPersona::ADMIN));
	}

	public function testPublicPersonaCannotSeeAdminTool(): void
	{
		$this->assertFalse($this->tool('admin')->isVisibleTo(McpPersona::PUBLIC_));
	}

	public function testPublicPersonaSeesPublicTool(): void
	{
		$this->assertTrue($this->tool('public')->isVisibleTo(McpPersona::PUBLIC_));
	}

	public function testPublicPersonaCannotSeeAuthenticatedTool(): void
	{
		$this->assertFalse($this->tool('authenticated')->isVisibleTo(McpPersona::PUBLIC_));
	}

	public function testAuthenticatedPersonaCannotSeeAdminTool(): void
	{
		$this->assertFalse($this->tool('admin')->isVisibleTo(McpPersona::AUTHENTICATED));
	}

	public function testAuthenticatedPersonaSeesPublicTool(): void
	{
		$this->assertTrue($this->tool('public')->isVisibleTo(McpPersona::AUTHENTICATED));
	}

	public function testAuthenticatedPersonaSeesAuthenticatedTool(): void
	{
		$this->assertTrue($this->tool('authenticated')->isVisibleTo(McpPersona::AUTHENTICATED));
	}
}
