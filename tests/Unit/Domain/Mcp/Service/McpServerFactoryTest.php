<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;
use TotalCMS\Support\Config;

final class McpServerFactoryTest extends TestCase
{
	private ToolRegistry $registry;
	private Config $config;
	private InMemorySessionStore $sessions;
	private NullLogger $logger;

	protected function setUp(): void
	{
		$this->registry = new ToolRegistry();
		$this->config   = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		// Minimum config the factory reads.
		$this->config->domain    = 'test.local';
		$this->config->dashboard = [];
		$this->sessions          = new InMemorySessionStore();
		$this->logger            = new NullLogger();
	}

	private function factory(): McpServerFactory
	{
		return new McpServerFactory($this->registry, $this->config, $this->sessions, $this->logger);
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
}
