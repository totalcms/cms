<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;
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

	// ────────────────────────────────────────────────────────────────────
	// Task 7 fix round 1, findings #1/#2/#3: registration-time validation
	// for requirement-bearing tools. Each of these is a developer-facing
	// misconfiguration that would otherwise degrade silently at request
	// time instead of failing loudly where a developer/test would see it.
	// ────────────────────────────────────────────────────────────────────

	public function testRegisterRejectsRequirementWithoutInputSchema(): void
	{
		$registry = new ToolRegistry();
		$tool     = new McpToolDefinition(
			name: 'no_schema',
			description: 'd',
			access: 'authenticated',
			handler: static fn (string $collection): array => [],
			inputSchema: null,
			requires: new ToolRequirement(domain: 'objects', operation: 'create', collectionArg: 'collection'),
		);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('declares a ToolRequirement but has no inputSchema');

		$registry->register($tool);
	}

	public function testRegisterRejectsTargetBearingDomainWithoutCollectionArg(): void
	{
		$registry = new ToolRegistry();
		$tool     = new McpToolDefinition(
			name: 'no_collection_arg',
			description: 'd',
			access: 'authenticated',
			handler: static fn (): array => [],
			inputSchema: ['type' => 'object', 'properties' => []],
			requires: new ToolRequirement(domain: 'objects', operation: 'create', collectionArg: null),
		);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('sets no collectionArg');

		$registry->register($tool);
	}

	public function testRegisterRejectsCollectionsMetaDomainWithoutCollectionArg(): void
	{
		$registry = new ToolRegistry();
		$tool     = new McpToolDefinition(
			name: 'no_collection_arg_meta',
			description: 'd',
			access: 'authenticated',
			handler: static fn (): array => [],
			inputSchema: ['type' => 'object', 'properties' => []],
			requires: new ToolRequirement(domain: 'collections-meta', operation: 'read', collectionArg: null),
		);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('sets no collectionArg');

		$registry->register($tool);
	}

	public function testRegisterRejectsSchemasDomainWithoutCollectionArg(): void
	{
		$registry = new ToolRegistry();
		$tool     = new McpToolDefinition(
			name: 'no_collection_arg_schemas',
			description: 'd',
			access: 'authenticated',
			handler: static fn (): array => [],
			inputSchema: ['type' => 'object', 'properties' => []],
			requires: new ToolRequirement(domain: 'schemas', operation: 'read', collectionArg: null),
		);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('sets no collectionArg');

		$registry->register($tool);
	}

	public function testRegisterAllowsNonTargetBearingDomainsWithoutCollectionArg(): void
	{
		// 'cache' and 'site' have no per-target concept — ToolRequirement's
		// own isSatisfiedFor() ignores $target for these domains, so
		// requiring collectionArg would be nonsensical.
		$registry   = new ToolRegistry();
		$cacheTool  = new McpToolDefinition(
			name: 'cache_tool',
			description: 'd',
			access: 'authenticated',
			handler: static fn (): array => [],
			inputSchema: ['type' => 'object', 'properties' => []],
			requires: new ToolRequirement(domain: 'cache', operation: 'update', collectionArg: null),
		);
		$siteTool = new McpToolDefinition(
			name: 'site_tool',
			description: 'd',
			access: 'authenticated',
			handler: static fn (): array => [],
			inputSchema: ['type' => 'object', 'properties' => []],
			requires: new ToolRequirement(domain: 'site', operation: 'read', collectionArg: null),
		);

		$registry->register($cacheTool);
		$registry->register($siteTool);

		$this->assertSame($cacheTool, $registry->get('cache_tool'));
		$this->assertSame($siteTool, $registry->get('site_tool'));
	}

	public function testRegisterRejectsCollectionArgNotDeclaredInInputSchema(): void
	{
		$registry = new ToolRegistry();
		$tool     = new McpToolDefinition(
			name: 'typo_arg',
			description: 'd',
			access: 'authenticated',
			handler: static fn (string $collection): array => [],
			inputSchema: [
				'type'       => 'object',
				'properties' => ['collection' => ['type' => 'string']],
			],
			// Typo: doesn't match the declared 'collection' property.
			requires: new ToolRequirement(domain: 'objects', operation: 'create', collectionArg: 'collection_id'),
		);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is not a property in its inputSchema');

		$registry->register($tool);
	}

	public function testRegisterAllowsWellFormedRequirement(): void
	{
		$registry = new ToolRegistry();
		$tool     = new McpToolDefinition(
			name: 'well_formed',
			description: 'd',
			access: 'authenticated',
			handler: static fn (string $collection): array => [],
			inputSchema: [
				'type'       => 'object',
				'properties' => ['collection' => ['type' => 'string']],
			],
			requires: new ToolRequirement(domain: 'objects', operation: 'create', collectionArg: 'collection'),
		);

		$registry->register($tool);

		$this->assertSame($tool, $registry->get('well_formed'));
	}
}
