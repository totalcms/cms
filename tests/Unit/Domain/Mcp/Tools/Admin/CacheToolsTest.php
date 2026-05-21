<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tools\Admin;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;
use TotalCMS\Domain\Mcp\Tools\Admin\CacheTools;

final class CacheToolsTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $cacheManager;
	private CacheTools $tool;

	protected function setUp(): void
	{
		$this->cacheManager = $this->createMock(CacheManager::class);
		$this->tool         = new CacheTools($this->cacheManager);
	}

	public function testRegisterAddsCacheClearWithAdminAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$definition = $registry->get('clear_cache');
		$this->assertNotNull($definition);
		$this->assertSame('admin', $definition->access);
	}

	public function testCacheClearAnnotatedAsDestructive(): void
	{
		// Even though "clear" isn't deleting customer content, it IS a
		// destructive write from the caller's perspective: subsequent reads
		// return slower while caches warm. destructiveHint:true so MCP hosts
		// can surface it with appropriate caution.
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$ann = $registry->get('clear_cache')->annotations;
		$this->assertNotNull($ann);
		$this->assertFalse($ann->readOnlyHint);
		$this->assertTrue($ann->destructiveHint);
		// Clearing twice in a row is a no-op the second time — but the FIRST
		// run is destructive, so honest annotation is destructive:true /
		// idempotent:false.
		$this->assertFalse($ann->idempotentHint);
	}

	public function testHandlerDispatchesToCacheManagerAndReturnsStatus(): void
	{
		$status = [
			'apcu'   => ['cleared' => true, 'reason' => 'success'],
			'redis'  => ['cleared' => false, 'reason' => 'not available'],
		];
		$this->cacheManager->expects($this->once())
			->method('clearAllCaches')
			->willReturn($status);

		$result = $this->tool->handler();

		$this->assertSame($status, $result['backends']);
		// Aggregate flag tells the agent at a glance whether anything failed.
		$this->assertArrayHasKey('all_cleared', $result);
	}

	public function testAllClearedTrueOnlyWhenEveryAvailableBackendSucceeded(): void
	{
		// "Not available" backends are tolerated — don't count them as failure.
		$this->cacheManager->method('clearAllCaches')->willReturn([
			'apcu'  => ['cleared' => true,  'reason' => 'success'],
			'redis' => ['cleared' => false, 'reason' => 'not available'],
		]);

		$this->assertTrue($this->tool->handler()['all_cleared']);
	}

	public function testAllClearedFalseWhenAnyAvailableBackendFailed(): void
	{
		$this->cacheManager->method('clearAllCaches')->willReturn([
			'apcu'  => ['cleared' => true,  'reason' => 'success'],
			'redis' => ['cleared' => false, 'reason' => 'connection refused'],
		]);

		$this->assertFalse($this->tool->handler()['all_cleared']);
	}
}
