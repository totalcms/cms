<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tools\Admin;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;
use TotalCMS\Domain\Mcp\Tools\Admin\ExtensionTools;

final class ExtensionToolsTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $extensionManager;
	private ExtensionTools $tool;

	protected function setUp(): void
	{
		$this->extensionManager = $this->createMock(ExtensionManager::class);
		$this->tool             = new ExtensionTools($this->extensionManager);
	}

	public function testRegisterAddsExtensionListWithAdminAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$definition = $registry->get('list_extensions');
		$this->assertNotNull($definition);
		$this->assertSame('admin', $definition->access);
	}

	public function testExtensionListAnnotatedAsReadOnlyIdempotent(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$ann = $registry->get('list_extensions')->annotations;
		$this->assertNotNull($ann);
		$this->assertTrue($ann->readOnlyHint);
		$this->assertTrue($ann->idempotentHint);
	}

	public function testHandlerReturnsExtensionsAsArray(): void
	{
		// ExtensionManager::listExtensions returns rich per-extension info —
		// id, name, enabled flag, capabilities, version, etc. We pass it
		// through verbatim so the agent gets the same picture as the admin UI.
		$extensions = [
			['id' => 'acme/feature', 'name' => 'Acme Feature', 'enabled' => true,  'capabilities' => ['twigFunction']],
			['id' => 'beta/widget',  'name' => 'Beta Widget',  'enabled' => false, 'capabilities' => []],
		];
		$this->extensionManager->expects($this->once())
			->method('listExtensions')
			->willReturn($extensions);

		$result = $this->tool->handler();

		$this->assertSame($extensions, $result['extensions']);
		$this->assertSame(2, $result['total']);
	}

	public function testHandlerHandlesEmptyExtensionList(): void
	{
		$this->extensionManager->method('listExtensions')->willReturn([]);

		$result = $this->tool->handler();

		$this->assertSame([], $result['extensions']);
		$this->assertSame(0, $result['total']);
	}
}
