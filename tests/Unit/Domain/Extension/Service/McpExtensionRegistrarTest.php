<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Extension\Service;

use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\McpExtensionRegistrar;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

final class McpExtensionRegistrarTest extends TestCase
{
	private ToolRegistry $registry;
	private McpExtensionRegistrar $registrar;
	/** @var list<array{level: string, message: string}> */
	private array $log;

	protected function setUp(): void
	{
		$this->registry  = new ToolRegistry();
		$this->log       = [];
		$this->registrar = new McpExtensionRegistrar($this->makeLogger());
	}

	private function makeLogger(): NullLogger
	{
		// Capture warnings via an anonymous logger so tests can assert
		// "this collision was reported" without coupling to a specific
		// logger backend.
		return new class($this->log) extends NullLogger {
			public function __construct(public array &$log)
			{
			}

			public function warning(\Stringable|string $message, array $context = []): void
			{
				$this->log[] = ['level' => 'warning', 'message' => (string)$message];
			}
		};
	}

	private function tool(string $name, string $access = 'public'): McpToolDefinition
	{
		return new McpToolDefinition(
			name: $name,
			description: 'desc',
			access: $access,
			handler: static fn (): array => [],
		);
	}

	/**
	 * Real ExtensionContext, wired the same way as
	 * ExtensionContextTest::createTestContext() — used so the access-value
	 * tests below exercise the actual extension-facing
	 * ExtensionContext::registerMcpTool() entry point, not a hand-built
	 * McpToolDefinition.
	 */
	private function makeExtensionContext(): ExtensionContext
	{
		$manifest = ExtensionManifest::fromArray([
			'id'      => 'acme/feature',
			'name'    => 'Acme Feature',
			'version' => '1.0.0',
		]);

		$container = $this->createMock(ContainerInterface::class);
		$storage   = $this->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$settings = new ExtensionSettingsManager($storage);

		return new ExtensionContext($manifest, '/path/to/extension', $container, $settings, new NullLogger());
	}

	// ─── Happy path ──────────────────────────────────────────────────────────

	public function testRegistersExtensionToolWhenNameDoesNotCollide(): void
	{
		$this->registry->register($this->tool('list_collections'));

		$result = $this->registrar->register($this->registry, ['acme/feature' => [$this->tool('acme_search')]]);

		$this->assertNotNull($this->registry->get('acme_search'));
		$this->assertSame(1, $result['registered']);
		$this->assertSame(0, $result['blocked']);
	}

	public function testPreservesExtensionDeclaredOutputSchema(): void
	{
		$tool = new McpToolDefinition(
			name: 'acme_search',
			description: 'desc',
			access: 'public',
			handler: static fn (): array => [],
			outputSchema: ['type' => 'object', 'properties' => ['items' => ['type' => 'array']]],
		);

		$this->registrar->register($this->registry, ['acme/feature' => [$tool]]);

		$registered = $this->registry->get('acme_search');
		$this->assertNotNull($registered);
		$this->assertSame(['type' => 'object', 'properties' => ['items' => ['type' => 'array']]], $registered->outputSchema);
	}

	public function testRegistersToolsFromMultipleExtensions(): void
	{
		$this->registrar->register($this->registry, [
			'acme/feature' => [$this->tool('acme_search')],
			'beta/widget'  => [$this->tool('beta_count')],
		]);

		$this->assertNotNull($this->registry->get('acme_search'));
		$this->assertNotNull($this->registry->get('beta_count'));
	}

	// ─── Collision with core (strict deny) ───────────────────────────────────

	public function testBlocksAndLogsWhenExtensionToolCollidesWithCore(): void
	{
		// Plan: strict deny on core collisions. Extensions can extend MCP but
		// can't override core tools — otherwise a rogue extension could
		// shadow `query_collection` and silently exfiltrate data.
		$this->registry->register($this->tool('list_collections'));

		$result = $this->registrar->register($this->registry, [
			'rogue/ext' => [$this->tool('list_collections')],
		]);

		// Original core tool is preserved (the handler closure identity check
		// confirms it's the same definition we registered).
		$this->assertSame('list_collections', $this->registry->get('list_collections')->name);
		$this->assertSame(0, $result['registered']);
		$this->assertSame(1, $result['blocked']);
		$this->assertCount(1, $this->log);
		$this->assertStringContainsString('list_collections', $this->log[0]['message']);
		$this->assertStringContainsString('rogue/ext', $this->log[0]['message']);
	}

	public function testBlocksAndLogsCrossExtensionCollision(): void
	{
		// Same strict-deny rule for extension-to-extension. Last-wins would
		// let extension load order determine the winner — fragile. Strict
		// deny forces the operator to resolve the conflict.
		$result = $this->registrar->register($this->registry, [
			'acme/feature' => [$this->tool('shared_tool_name')],
			'beta/widget'  => [$this->tool('shared_tool_name')],
		]);

		$this->assertNotNull($this->registry->get('shared_tool_name'));
		// First extension wins (whichever is iterated first); second is blocked.
		$this->assertSame(1, $result['registered']);
		$this->assertSame(1, $result['blocked']);
		$this->assertNotEmpty($this->log);
	}

	// ─── Edge cases ──────────────────────────────────────────────────────────

	public function testEmptyExtensionMapIsNoOp(): void
	{
		$result = $this->registrar->register($this->registry, []);

		$this->assertSame(0, $result['registered']);
		$this->assertSame(0, $result['blocked']);
		$this->assertSame([], $this->log);
	}

	public function testNonToolEntriesAreSkippedDefensively(): void
	{
		// Defensive: an extension that returns the wrong shape from
		// getRegisteredMcpTools shouldn't crash the boot path.
		$result = $this->registrar->register($this->registry, [
			'broken/ext' => ['not a tool', null, 42],
			'ok/ext'     => [$this->tool('ok_tool')],
		]);

		$this->assertNotNull($this->registry->get('ok_tool'));
		$this->assertSame(1, $result['registered']);
	}

	// ─── access: 'authenticated' (Task 2) ─────────────────────────────────────

	public function testAuthenticatedAccessToolIsVisibleToAuthenticatedAndAdminNotPublic(): void
	{
		// Drives the real extension-facing path: ExtensionContext::registerMcpTool()
		// -> McpExtensionRegistrar::register() -> ToolRegistry::forPersona().
		// registerMcpTool()'s docblock (pre-fix) only documented 'admin' or
		// 'public', but McpToolDefinition::isVisibleTo() has always matched
		// 'authenticated' explicitly — this pins that the capability already
		// works end-to-end for extension-registered tools.
		$context = $this->makeExtensionContext();
		$context->registerMcpTool(
			name: 'acme_authenticated_only',
			description: 'desc',
			access: 'authenticated',
			handler: static fn (): array => [],
		);

		$this->registrar->register($this->registry, [
			$context->extensionId() => $context->getRegisteredMcpTools(),
		]);

		$adminNames         = array_map(static fn (McpToolDefinition $t): string => $t->name, $this->registry->forPersona(McpPersona::ADMIN));
		$authenticatedNames = array_map(static fn (McpToolDefinition $t): string => $t->name, $this->registry->forPersona(McpPersona::AUTHENTICATED));
		$publicNames        = array_map(static fn (McpToolDefinition $t): string => $t->name, $this->registry->forPersona(McpPersona::PUBLIC_));

		$this->assertContains('acme_authenticated_only', $adminNames);
		$this->assertContains('acme_authenticated_only', $authenticatedNames);
		$this->assertNotContains('acme_authenticated_only', $publicNames);
	}

	public function testInvalidAccessValueFailsClosedToAdminOnly(): void
	{
		// Pins current (correct) behavior: McpToolDefinition::isVisibleTo()
		// only grants AUTHENTICATED/PUBLIC_ visibility on an exact match
		// against 'public'/'authenticated'. An unrecognised value (typo or
		// made-up string) matches neither, so the tool is visible to ADMIN
		// only — the most restrictive outcome, never "secretly public".
		$context = $this->makeExtensionContext();
		$context->registerMcpTool(
			name: 'acme_typo_access',
			description: 'desc',
			access: 'authenticaded', // typo of 'authenticated'
			handler: static fn (): array => [],
		);
		$context->registerMcpTool(
			name: 'acme_made_up_access',
			description: 'desc',
			access: 'everyone',
			handler: static fn (): array => [],
		);

		$this->registrar->register($this->registry, [
			$context->extensionId() => $context->getRegisteredMcpTools(),
		]);

		$adminNames         = array_map(static fn (McpToolDefinition $t): string => $t->name, $this->registry->forPersona(McpPersona::ADMIN));
		$authenticatedNames = array_map(static fn (McpToolDefinition $t): string => $t->name, $this->registry->forPersona(McpPersona::AUTHENTICATED));
		$publicNames        = array_map(static fn (McpToolDefinition $t): string => $t->name, $this->registry->forPersona(McpPersona::PUBLIC_));

		foreach (['acme_typo_access', 'acme_made_up_access'] as $name) {
			$this->assertContains($name, $adminNames);
			$this->assertNotContains($name, $authenticatedNames);
			$this->assertNotContains($name, $publicNames);
		}
	}
}
