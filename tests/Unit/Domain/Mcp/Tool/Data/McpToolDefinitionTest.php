<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Data;

use Mcp\Schema\ToolAnnotations;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;

final class McpToolDefinitionTest extends TestCase
{
	private function tool(string $access): McpToolDefinition
	{
		return new McpToolDefinition(
			name: 'example_tool',
			description: 'Example',
			access: $access,
			handler: static fn (): array => [],
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
		$tool = new McpToolDefinition('t', 'd', 'public', static fn (): null => null);

		$this->assertNull($tool->inputSchema);
	}

	public function testDescriptionBuilderDefaultsToNull(): void
	{
		// Tools that aren't persona-aware leave the dynamic builder unset and rely
		// solely on the static description string.
		$tool = new McpToolDefinition('t', 'static-desc', 'public', static fn (): null => null);

		$this->assertNull($tool->descriptionBuilder);
	}

	public function testAnnotationsDefaultsToNull(): void
	{
		// Tools that don't override annotations fall back to the McpServerFactory
		// default (read-only). Required cross-cutting requirement before
		// Anthropic Directory submission — only destructive tools need to
		// explicitly opt out of the read-only default.
		$tool = new McpToolDefinition('t', 'd', 'public', static fn (): null => null);

		$this->assertNull($tool->annotations);
	}

	public function testAnnotationsCanCarryFullToolMetadata(): void
	{
		// Destructive tools (delete_schema, clear_cache, template_delete) need
		// the full set: title + readOnlyHint:false + destructiveHint:true +
		// idempotentHint:false. Anthropic Directory review treats missing
		// annotations as pass/fail.
		$annotations = new ToolAnnotations(
			title: 'Delete Schema',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
		);
		$tool = new McpToolDefinition(
			name: 'delete_schema',
			description: 'd',
			access: 'admin',
			handler: static fn (): null => null,
			inputSchema: null,
			descriptionBuilder: null,
			annotations: $annotations,
		);

		$this->assertSame($annotations, $tool->annotations);
	}

	public function testDescriptionBuilderCanBeSetAndInvokedWithPersona(): void
	{
		// Phase 1 content tools (query_collection, get_object, search_collection)
		// expose a per-persona description builder so the field catalog appended to
		// the tool description matches what the caller is actually allowed to see.
		$builder = static fn (McpPersona $persona): string => 'desc-for-' . $persona->value;
		$tool    = new McpToolDefinition(
			name: 't',
			description: 'fallback',
			access: 'public',
			handler: static fn (): null => null,
			inputSchema: null,
			descriptionBuilder: $builder,
		);

		$this->assertSame($builder, $tool->descriptionBuilder);
		$this->assertSame('desc-for-admin', ($tool->descriptionBuilder)(McpPersona::ADMIN));
		$this->assertSame('desc-for-public', ($tool->descriptionBuilder)(McpPersona::PUBLIC_));
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

	public function testOutputSchemaDefaultsToNull(): void
	{
		// Tools that don't declare an outputSchema get null — the SDK's
		// addTool() default. Content + discovery tools that return
		// structured data opt in to a schema for SDK-aware host validation.
		$tool = new McpToolDefinition('t', 'd', 'public', static fn (): null => null);

		$this->assertNull($tool->outputSchema);
	}

	public function testOutputSchemaCanCarryArbitraryJsonSchema(): void
	{
		// The shape is forwarded verbatim to addTool()'s outputSchema arg,
		// then exposed in tools/list for hosts that pre-validate results.
		$schema = [
			'type'       => 'object',
			'required'   => ['items', 'total'],
			'properties' => [
				'items' => ['type' => 'array'],
				'total' => ['type' => 'integer'],
			],
		];
		$tool = new McpToolDefinition(
			name: 't',
			description: 'd',
			access: 'public',
			handler: static fn (): null => null,
			outputSchema: $schema,
		);

		$this->assertSame($schema, $tool->outputSchema);
	}
}
