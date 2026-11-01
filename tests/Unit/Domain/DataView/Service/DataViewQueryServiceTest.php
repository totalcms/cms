<?php

namespace Tests\Unit\Domain\DataView\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\DataView\Service\DataViewBuilder;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\DataView\Service\DataViewQueryService;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Query\Service\QueryPipeline;

final class DataViewQueryServiceTest extends TestCase
{
	private MockObject&DataViewFetcher $fetcher;
	private MockObject&DataViewBuilder $builder;
	private MockObject&QueryPipeline $pipeline;
	private DataViewQueryService $service;

	/** @var array<mixed>|null what the loader handed the pipeline, once it ran */
	private ?array $loaded = null;

	protected function setUp(): void
	{
		$this->fetcher  = $this->createMock(DataViewFetcher::class);
		$this->builder  = $this->createMock(DataViewBuilder::class);
		$this->pipeline = $this->createMock(QueryPipeline::class);
		$cache          = $this->createMock(CacheManager::class);
		$cache->method('queryVersion')->willReturn('v1');
		$this->service = new DataViewQueryService($this->fetcher, $this->builder, $this->pipeline, $cache);
	}

	/** Make the mocked pipeline run the loader, as a cache miss does. */
	private function pipelineLoads(QueryResult $result): void
	{
		$this->pipeline->method('execute')->willReturnCallback(function (array|\Closure $items) use ($result): QueryResult {
			$this->loaded = $items instanceof \Closure ? $items() : $items;

			return $result;
		});
	}

	public function testDelegatesToPipelineWithCorrectCachePrefix(): void
	{
		$this->fetcher->method('dataExists')->willReturn(true);
		$this->fetcher->method('getViewData')->willReturn([
			['id' => '1', 'title' => 'Item 1'],
		]);

		$expected = new QueryResult([['id' => '1', 'title' => 'Item 1']], 1, 20, 0);

		$this->pipeline->expects($this->once())
			->method('execute')
			->with(
				$this->anything(),
				$this->anything(),
				'dataview-query:my-view@v1',
			)
			->willReturn($expected);

		$result = $this->service->query('my-view', []);

		$this->assertSame($expected, $result);
	}

	public function testAutoBuildsViewWhenDataDoesNotExist(): void
	{
		$this->fetcher->method('dataExists')->willReturn(false);
		$this->fetcher->method('getViewData')->willReturn([
			['id' => '1', 'title' => 'Built Item'],
		]);

		$this->builder->expects($this->once())
			->method('buildView')
			->with('my-view');

		$this->pipelineLoads(new QueryResult([], 0, 20, 0));

		$this->service->query('my-view', []);
	}

	public function testDoesNotBuildWhenDataExists(): void
	{
		$this->fetcher->method('dataExists')->willReturn(true);
		$this->fetcher->method('getViewData')->willReturn([
			['id' => '1', 'title' => 'Item'],
		]);

		$this->builder->expects($this->never())
			->method('buildView');

		$this->pipelineLoads(new QueryResult([], 0, 20, 0));

		$this->service->query('my-view', []);
	}

	public function testReturnsEmptyResultForEmptyViewData(): void
	{
		$this->fetcher->method('dataExists')->willReturn(true);
		$this->fetcher->method('getViewData')->willReturn([]);

		$this->pipelineLoads(new QueryResult([], 0, 20, 0));

		$this->service->query('my-view', []);

		$this->assertSame([], $this->loaded);
	}

	public function testReturnsEmptyForNonArrayOfObjectsData(): void
	{
		$this->fetcher->method('dataExists')->willReturn(true);
		$this->fetcher->method('getViewData')->willReturn(['just', 'strings']);

		$this->pipelineLoads(new QueryResult([], 0, 20, 0));

		$this->service->query('my-view', []);

		$this->assertSame([], $this->loaded);
	}

	public function testHandlesStringKeyedArraysReindexing(): void
	{
		$this->fetcher->method('dataExists')->willReturn(true);
		$this->fetcher->method('getViewData')->willReturn([
			'key-a' => ['id' => '1', 'title' => 'First'],
			'key-b' => ['id' => '2', 'title' => 'Second'],
		]);

		$this->pipelineLoads(new QueryResult([], 0, 20, 0));

		$this->service->query('my-view', []);

		// Re-indexed to sequential 0, 1
		$this->assertSame([0, 1], array_keys($this->loaded ?? []));
		$this->assertSame(['1', '2'], array_column($this->loaded ?? [], 'id'));
	}

	public function testPassesParamsToQueryPipeline(): void
	{
		$this->fetcher->method('dataExists')->willReturn(true);
		$this->fetcher->method('getViewData')->willReturn([
			['id' => '1', 'title' => 'Item'],
		]);

		$params   = ['limit' => '5', 'offset' => '10', 'sort' => 'title:asc'];
		$expected = new QueryResult([], 0, 5, 10);

		$this->pipeline->expects($this->once())
			->method('execute')
			->with($this->anything(), $params, $this->anything())
			->willReturn($expected);

		$this->service->query('my-view', $params);
	}
}
