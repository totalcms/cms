<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Discovery;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Discovery\ListViewsTool;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

final class ListViewsToolTest extends TestCase
{
	/** @var MockObject&DataViewLister */
	private MockObject $lister;
	private PersonaContext $persona;
	private ListViewsTool $tool;

	protected function setUp(): void
	{
		$this->lister  = $this->createMock(DataViewLister::class);
		$this->persona = new PersonaContext();
		$this->tool    = new ListViewsTool($this->lister, $this->persona);
	}

	// ── Registration ─────────────────────────────────────────────────────────

	public function testRegisterAddsToolWithExpectedNameAndPublicAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$tool = $registry->get('list_views');
		$this->assertNotNull($tool);
		$this->assertSame('public', $tool->access);
		$this->assertNotNull($tool->outputSchema);
	}

	// ── Persona filtering ────────────────────────────────────────────────────

	public function testPublicPersonaSeesOnlyPublicViews(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->lister->method('listViews')->willReturn([
			['id' => 'public-one',  'name' => 'Public One',  'mcp' => ['access' => 'public']],
			['id' => 'admin-only',  'name' => 'Admin Only',  'mcp' => ['access' => 'admin']],
			['id' => 'public-two',  'name' => 'Public Two',  'mcp' => ['access' => 'public']],
			['id' => 'no-mcp-block', 'name' => 'Defaults Admin'], // missing mcp → defaults to admin
		]);

		$result = $this->tool->handler();

		$this->assertSame(2, $result['total']);
		$ids = array_column($result['views'], 'id');
		$this->assertSame(['public-one', 'public-two'], $ids);
	}

	public function testAdminPersonaSeesEveryView(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->lister->method('listViews')->willReturn([
			['id' => 'a-public', 'mcp' => ['access' => 'public']],
			['id' => 'b-admin',  'mcp' => ['access' => 'admin']],
			['id' => 'c-auth',   'mcp' => ['access' => 'authenticated']],
		]);

		$result = $this->tool->handler();

		$this->assertSame(3, $result['total']);
	}

	public function testOutputSortedAlphabeticallyById(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->lister->method('listViews')->willReturn([
			['id' => 'zebra', 'mcp' => ['access' => 'public']],
			['id' => 'alpha', 'mcp' => ['access' => 'public']],
			['id' => 'mango', 'mcp' => ['access' => 'public']],
		]);

		$ids = array_column($this->tool->handler()['views'], 'id');

		$this->assertSame(['alpha', 'mango', 'zebra'], $ids);
	}

	// ── Description fallbacks ────────────────────────────────────────────────

	public function testDescriptionPrefersMcpDescriptionThenObjectDescriptionThenEmpty(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->lister->method('listViews')->willReturn([
			['id' => 'a', 'mcp' => ['access' => 'public', 'description' => 'mcp text']],
			['id' => 'b', 'mcp' => ['access' => 'public'], 'description' => 'view text'],
			['id' => 'c', 'mcp' => ['access' => 'public']],
		]);

		$byId = [];
		foreach ($this->tool->handler()['views'] as $view) {
			$byId[$view['id']] = $view['description'];
		}

		$this->assertSame('mcp text', $byId['a']);
		$this->assertSame('view text', $byId['b']);
		$this->assertSame('', $byId['c']);
	}

	public function testEmptyIdsAreSkipped(): void
	{
		// Defensive: views with no id are dropped silently rather than
		// surfacing a broken record into the agent.
		$this->persona->set(McpPersona::ADMIN);
		$this->lister->method('listViews')->willReturn([
			['id' => '',   'mcp' => ['access' => 'public']],
			['id' => 'ok', 'mcp' => ['access' => 'public']],
		]);

		$result = $this->tool->handler();
		$this->assertSame(['ok'], array_column($result['views'], 'id'));
	}
}
