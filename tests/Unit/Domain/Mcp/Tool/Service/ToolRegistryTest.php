<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

final class ToolRegistryTest extends TestCase
{
	private function tool(string $name, string $access = 'public'): McpToolDefinition
	{
		return new McpToolDefinition($name, 'desc-' . $name, $access, static fn (): array => []);
	}

	public function testRegisterStoresTool(): void
	{
		$registry = new ToolRegistry();
		$tool     = $this->tool('alpha');

		$registry->register($tool);

		$this->assertSame($tool, $registry->get('alpha'));
	}

	public function testGetReturnsNullForUnknownName(): void
	{
		$registry = new ToolRegistry();

		$this->assertNull($registry->get('missing'));
	}

	public function testRegisterRejectsDuplicateName(): void
	{
		$registry = new ToolRegistry();
		$registry->register($this->tool('dup'));

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('MCP tool "dup" is already registered.');

		$registry->register($this->tool('dup'));
	}

	public function testUnregisterRemovesTool(): void
	{
		$registry = new ToolRegistry();
		$registry->register($this->tool('temp'));

		$registry->unregister('temp');

		$this->assertNull($registry->get('temp'));
	}

	public function testUnregisterIsIdempotent(): void
	{
		$registry = new ToolRegistry();

		// Should not throw on a name that was never registered.
		$registry->unregister('never-existed');

		$this->assertNull($registry->get('never-existed'));
	}

	public function testAllReturnsAllRegisteredTools(): void
	{
		$registry = new ToolRegistry();
		$registry->register($this->tool('z_last'));
		$registry->register($this->tool('a_first'));

		$this->assertCount(2, $registry->all());
	}

	public function testForPersonaReturnsOnlyVisibleToolsSortedByName(): void
	{
		$registry = new ToolRegistry();
		$registry->register($this->tool('z_public', 'public'));
		$registry->register($this->tool('a_admin', 'admin'));
		$registry->register($this->tool('m_public', 'public'));

		$visible = $registry->forPersona(McpPersona::PUBLIC_);

		$this->assertCount(2, $visible);
		$this->assertSame('m_public', $visible[0]->name);
		$this->assertSame('z_public', $visible[1]->name);
	}

	public function testForPersonaAdminSeesEverythingSorted(): void
	{
		$registry = new ToolRegistry();
		$registry->register($this->tool('z_public', 'public'));
		$registry->register($this->tool('a_admin', 'admin'));
		$registry->register($this->tool('m_authenticated', 'authenticated'));

		$visible = $registry->forPersona(McpPersona::ADMIN);

		$this->assertCount(3, $visible);
		$this->assertSame(['a_admin', 'm_authenticated', 'z_public'], array_map(
			static fn (McpToolDefinition $t): string => $t->name,
			$visible,
		));
	}

	public function testForPersonaReturnsEmptyArrayWhenNoMatches(): void
	{
		$registry = new ToolRegistry();
		$registry->register($this->tool('admin_only', 'admin'));

		$this->assertSame([], $registry->forPersona(McpPersona::PUBLIC_));
	}
}
