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

	public function testContainsFilterMatchesScalarFields(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$items = [
			['id' => '1', 'title' => 'Hello World'],
			['id' => '2', 'title' => 'Goodbye'],
		];

		$result = $this->pipeline->execute($items, ['filter' => 'world'], 'test');

		$this->assertSame(['1'], array_column($result->items, 'id'));
	}

	public function testContainsFilterMatchesListFieldItems(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$items = [
			['id' => '1', 'title' => 'First', 'tags' => ['travel', 'food']],
			['id' => '2', 'title' => 'Second', 'tags' => ['tech']],
		];

		$result = $this->pipeline->execute($items, ['filter' => 'trav'], 'test');

		$this->assertSame(['1'], array_column($result->items, 'id'));
	}

	public function testContainsFilterMatchesListFieldCaseInsensitively(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		$items = [
			['id' => '1', 'categories' => ['Announcements']],
			['id' => '2', 'categories' => ['Reviews']],
		];

		$result = $this->pipeline->execute($items, ['filter' => 'announce'], 'test');

		$this->assertSame(['1'], array_column($result->items, 'id'));
	}

	public function testContainsFilterIgnoresNestedObjectFields(): void
	{
		$this->cacheManager->method('getApiResponse')->willReturn(null);

		// An image (associative composite) must not leak its internal metadata
		// into the filter — otherwise "png" would match every object.
		$items = [
			['id' => '1', 'title' => 'First', 'image' => ['name' => 'shot.png', 'mime' => 'image/png']],
			['id' => '2', 'title' => 'Second', 'image' => ['name' => 'other.png', 'mime' => 'image/png']],
		];

		$result = $this->pipeline->execute($items, ['filter' => 'png'], 'test');

		$this->assertSame(0, $result->total);
	}

	// --- Ids (show-selected) ---

	public function testIdsFilterRestrictsToTheGivenIds(): void
	{
		$items  = $this->makeItems(5);
		$result = $this->pipeline->execute($items, ['ids' => '2,4'], 'test');

		$this->assertSame(2, $result->total);
		$this->assertSame(['2', '4'], array_column($result->items, 'id'));
	}

	public function testIdsFilterTrimsAndIgnoresUnknownIds(): void
	{
		$items  = $this->makeItems(3);
		$result = $this->pipeline->execute($items, ['ids' => ' 1 , nope , 3 '], 'test');

		$this->assertSame(['1', '3'], array_column($result->items, 'id'));
	}

	public function testIdsFilterTakesPrecedenceOverFilter(): void
	{
		$items = [
			['id' => '1', 'title' => 'keep me'],
			['id' => '2', 'title' => 'other'],
		];

		// Both ids and filter present — ids wins, so the filter term is ignored.
		$result = $this->pipeline->execute($items, ['ids' => '2', 'filter' => 'keep'], 'test');

		$this->assertSame(['2'], array_column($result->items, 'id'));
	}

	public function testIdsFilterSkipsCache(): void
	{
		$this->cacheManager->expects($this->never())->method('getApiResponse');
		$this->cacheManager->expects($this->never())->method('storeApiResponse');

		$this->pipeline->execute($this->makeItems(3), ['ids' => '1'], 'test');
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
