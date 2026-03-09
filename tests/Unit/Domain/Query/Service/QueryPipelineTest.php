<?php

namespace Tests\Unit\Domain\Query\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Query\Service\ObjectFilter;
use TotalCMS\Domain\Query\Service\ObjectSearcher;
use TotalCMS\Domain\Query\Service\QueryPipeline;

final class QueryPipelineTest extends TestCase
{
	private MockObject&CacheManager $cacheManager;
	private QueryPipeline $pipeline;

	protected function setUp(): void
	{
		$this->cacheManager = $this->createMock(CacheManager::class);
		$this->pipeline     = new QueryPipeline(
			new ObjectFilter(),
			new ObjectSearcher(),
			$this->cacheManager,
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function makeItems(int $count): array
	{
		$items = [];
		for ($i = 1; $i <= $count; $i++) {
			$items[] = ['id' => (string)$i, 'title' => "Item $i"];
		}

		return $items;
	}

	// --- Pagination ---

	public function testDefaultLimitIs20(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$items  = $this->makeItems(50);
		$result = $this->pipeline->execute($items, [], 'test');

		$this->assertSame(20, $result->limit);
		$this->assertCount(20, $result->items);
		$this->assertSame(50, $result->total);
	}

	public function testCustomLimit(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$items  = $this->makeItems(50);
		$result = $this->pipeline->execute($items, ['limit' => '5'], 'test');

		$this->assertSame(5, $result->limit);
		$this->assertCount(5, $result->items);
	}

	public function testMaxLimitIs100(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$items  = $this->makeItems(200);
		$result = $this->pipeline->execute($items, ['limit' => '999'], 'test');

		$this->assertSame(100, $result->limit);
		$this->assertCount(100, $result->items);
	}

	public function testMinLimitIs1(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$items  = $this->makeItems(10);
		$result = $this->pipeline->execute($items, ['limit' => '0'], 'test');

		$this->assertSame(1, $result->limit);
		$this->assertCount(1, $result->items);
	}

	public function testOffsetSlicing(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$items  = $this->makeItems(10);
		$result = $this->pipeline->execute($items, ['limit' => '3', 'offset' => '3'], 'test');

		$this->assertSame(10, $result->total);
		$this->assertSame(3, $result->offset);
		$this->assertCount(3, $result->items);
		$this->assertSame('4', $result->items[0]['id']);
	}

	public function testNegativeOffsetClampedToZero(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$items  = $this->makeItems(5);
		$result = $this->pipeline->execute($items, ['limit' => '5', 'offset' => '-10'], 'test');

		$this->assertSame(0, $result->offset);
		$this->assertSame('1', $result->items[0]['id']);
	}

	// --- Search ---

	public function testSearchDelegatesToObjectSearcher(): void
	{
		$items = [
			['id' => '1', 'title' => 'Red table'],
			['id' => '2', 'title' => 'Blue chair'],
			['id' => '3', 'title' => 'Red chair'],
		];

		$result = $this->pipeline->execute($items, ['search' => 'table'], 'test');

		$this->assertSame(1, $result->total);
		$this->assertSame('1', $result->items[0]['id']);
	}

	public function testSearchSkipsCache(): void
	{
		$this->cacheManager->expects($this->never())
			->method('getApiResponse');
		$this->cacheManager->expects($this->never())
			->method('storeApiResponse');

		$items = [['id' => '1', 'title' => 'Test']];
		$this->pipeline->execute($items, ['search' => 'test'], 'test');
	}

	// --- Filter ---

	public function testFilterDelegatesToObjectFilter(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$items = [
			['id' => '1', 'published' => true],
			['id' => '2', 'published' => false],
		];

		$result = $this->pipeline->execute($items, ['include' => 'published:true'], 'test');

		$this->assertSame(1, $result->total);
		$this->assertSame('1', $result->items[0]['id']);
	}

	// --- Sort ---

	public function testSortByField(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$items = [
			['id' => '1', 'title' => 'Charlie'],
			['id' => '2', 'title' => 'Alpha'],
			['id' => '3', 'title' => 'Bravo'],
		];

		$result = $this->pipeline->execute($items, ['sort' => 'title:asc'], 'test');

		$this->assertSame('Alpha', $result->items[0]['title']);
		$this->assertSame('Bravo', $result->items[1]['title']);
		$this->assertSame('Charlie', $result->items[2]['title']);
	}

	// --- Cache ---

	public function testCacheHitReturnsCachedResult(): void
	{
		$cached = new QueryResult([['id' => 'cached']], 1, 20, 0);

		$this->cacheManager->method('getApiResponse')
			->willReturn($cached);

		$items  = $this->makeItems(10);
		$result = $this->pipeline->execute($items, [], 'test');

		$this->assertSame('cached', $result->items[0]['id']);
	}

	public function testCacheMissStoresResult(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);
		$this->cacheManager->expects($this->once())
			->method('storeApiResponse');

		$items = $this->makeItems(5);
		$this->pipeline->execute($items, [], 'test');
	}

	// --- Combined ---

	public function testEmptyItemsReturnsEmptyResult(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$result = $this->pipeline->execute([], [], 'test');

		$this->assertSame(0, $result->total);
		$this->assertEmpty($result->items);
	}
}
