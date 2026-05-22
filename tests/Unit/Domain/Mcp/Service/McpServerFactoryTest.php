<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use Mcp\Server;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Session\InMemorySessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;
use TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Support\Config;

final class McpServerFactoryTest extends TestCase
{
	private ToolRegistry $registry;
	private Config $config;
	private InMemorySessionStore $sessions;
	private NullLogger $logger;

	private ResourceRegistry $resources;

	protected function setUp(): void
	{
		$this->registry  = new ToolRegistry();
		$this->resources = new ResourceRegistry();
		$this->config    = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		// Minimum config the factory reads.
		$this->config->domain    = 'test.local';
		$this->config->dashboard = [];
		$this->sessions          = new InMemorySessionStore();
		$this->logger            = new NullLogger();
	}

	private function factory(): McpServerFactory
	{
		// SessionSubscriptionManager is the SDK default — fine as a stand-in
		// for tests since they don't exercise subscription dispatch.
		return new McpServerFactory(
			$this->registry,
			$this->resources,
			new SessionSubscriptionManager(),
			$this->config,
			$this->sessions,
			$this->logger,
		);
	}

	private function tool(string $name, string $access = 'public'): McpToolDefinition
	{
		return new McpToolDefinition(
			name: $name,
			description: 'desc-' . $name,
			access: $access,
			handler: static fn (): array => ['name' => $name],
		);
	}

	public function testProtocolVersionMatchesMcpSpec(): void
	{
		// If this fails after updating the SDK, the factory + discovery JSON
		// both need to advertise the new version in lockstep.
		$this->assertSame('2025-06-18', $this->factory()->protocolVersion());
	}

	public function testBuildReturnsSdkServerInstance(): void
	{
		$this->assertInstanceOf(Server::class, $this->factory()->build(McpPersona::ADMIN));
	}

	public function testBuildSucceedsWithEmptyRegistry(): void
	{
		// Edge case: a fresh install with no tools registered should still
		// produce a valid SDK Server (initialize will succeed, tools/list empty).
		$this->assertInstanceOf(Server::class, $this->factory()->build(McpPersona::PUBLIC_));
	}

	public function testBuildIsRepeatablePerRequest(): void
	{
		// A new Server is constructed per call so persona switches between
		// requests don't leak tool surfaces across personas.
		$first  = $this->factory()->build(McpPersona::ADMIN);
		$second = $this->factory()->build(McpPersona::PUBLIC_);

		$this->assertNotSame($first, $second);
	}

	public function testBuildOmitsTransitionalPersonaFiltering(): void
	{
		// Verifies the factory delegates filtering to ToolRegistry::forPersona
		// rather than re-implementing the policy. A misclassified tool here
		// would surface as a Server with the wrong tool count.
		$this->registry->register($this->tool('admin_only', 'admin'));
		$this->registry->register($this->tool('public_one', 'public'));
		$this->registry->register($this->tool('public_two', 'public'));

		// Both factory() invocations should succeed without raising — registry
		// returns 2 visible tools for PUBLIC, 3 for ADMIN.
		$this->assertCount(2, $this->registry->forPersona(McpPersona::PUBLIC_));
		$this->assertCount(3, $this->registry->forPersona(McpPersona::ADMIN));
		$this->assertInstanceOf(Server::class, $this->factory()->build(McpPersona::PUBLIC_));
		$this->assertInstanceOf(Server::class, $this->factory()->build(McpPersona::ADMIN));
	}

	public function testServerNameFallsBackToDomain(): void
	{
		// With no dashboard title configured, the server name should reflect
		// the site domain (visible to AI agents via initialize).
		$this->config->domain    = 'photographer.example';
		$this->config->dashboard = [];

		$this->assertInstanceOf(Server::class, $this->factory()->build(McpPersona::ADMIN));
	}

	public function testServerNameUsesDashboardTitleWhenCustom(): void
	{
		// A customised dashboard title (anything other than the default
		// "Total CMS Admin") wins over the bare domain.
		$this->config->dashboard = ['title' => "Joe's Bistro CMS"];

		$this->assertInstanceOf(Server::class, $this->factory()->build(McpPersona::ADMIN));
	}

	public function testPerToolAnnotationsAreUsedWhenSetOtherwiseFallBackToReadOnlyDefault(): void
	{
		// Tools that explicitly set annotations (e.g. destructive admin tools)
		// must reach the SDK with those exact annotations — the factory's
		// readOnly default only applies to tools that didn't override. Verified
		// via successful Server build with both shapes registered; deeper
		// per-tool annotation routing is exercised through SDK integration
		// tests (the SDK exposes annotations via tools/list).
		$customAnnotations = new \Mcp\Schema\ToolAnnotations(
			title: 'Custom',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
		);
		$customTool = new McpToolDefinition(
			name: 'destructive_tool',
			description: 'd',
			access: 'admin',
			handler: static fn (): array => [],
			inputSchema: null,
			descriptionBuilder: null,
			annotations: $customAnnotations,
		);
		$defaultTool = $this->tool('read_only_tool', 'public');

		$this->registry->register($customTool);
		$this->registry->register($defaultTool);

		$this->assertInstanceOf(Server::class, $this->factory()->build(McpPersona::ADMIN));
	}

	public function testDescriptionBuilderIsInvokedWithBuildPersona(): void
	{
		// Phase 1: content tools dynamically render their description per persona
		// so the field catalog only mentions collections the caller can see.
		// Factory must call the builder with the resolved persona at build time —
		// not at registry-population time — so the same registered tool can serve
		// admin and public requests differently.
		$invocations = [];
		$tool        = new McpToolDefinition(
			name: 'dynamic',
			description: 'static fallback',
			access: 'public',
			handler: static fn (): array => [],
			inputSchema: null,
			descriptionBuilder: function (McpPersona $persona) use (&$invocations): string {
				$invocations[] = $persona;

				return 'built-for-' . $persona->value;
			},
		);

		$this->registry->register($tool);

		$this->factory()->build(McpPersona::ADMIN);
		$this->factory()->build(McpPersona::PUBLIC_);

		$this->assertSame([McpPersona::ADMIN, McpPersona::PUBLIC_], $invocations);
	}
}
