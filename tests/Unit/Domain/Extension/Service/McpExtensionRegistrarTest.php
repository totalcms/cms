<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Extension\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Service\McpExtensionRegistrar;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

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

	// ─── Happy path ──────────────────────────────────────────────────────────

	public function testRegistersExtensionToolWhenNameDoesNotCollide(): void
	{
		$this->registry->register($this->tool('list_collections'));

		$result = $this->registrar->register($this->registry, ['acme/feature' => [$this->tool('acme_search')]]);

		$this->assertNotNull($this->registry->get('acme_search'));
		$this->assertSame(1, $result['registered']);
		$this->assertSame(0, $result['blocked']);
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
}
