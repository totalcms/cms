<?php

namespace Tests\Unit\Domain\DataView\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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

	protected function setUp(): void
	{
		$this->fetcher  = $this->createMock(DataViewFetcher::class);
		$this->builder  = $this->createMock(DataViewBuilder::class);
		$this->pipeline = $this->createMock(QueryPipeline::class);
		$this->service  = new DataViewQueryService($this->fetcher, $this->builder, $this->pipeline);
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
				'dataview-query:my-view',
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

		$expected = new QueryResult([], 0, 20, 0);
		$this->pipeline->method('execute')->willReturn($expected);

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

		$expected = new QueryResult([], 0, 20, 0);
		$this->pipeline->method('execute')->willReturn($expected);

		$this->service->query('my-view', []);
	}

	public function testReturnsEmptyResultForEmptyViewData(): void
	{
		$this->fetcher->method('dataExists')->willReturn(true);
		$this->fetcher->method('getViewData')->willReturn([]);

		$expected = new QueryResult([], 0, 20, 0);

		$this->pipeline->expects($this->once())
			->method('execute')
			->with([], $this->anything(), $this->anything())
			->willReturn($expected);

		$result = $this->service->query('my-view', []);

		$this->assertSame($expected, $result);
	}

	public function testReturnsEmptyForNonArrayOfObjectsData(): void
	{
		$this->fetcher->method('dataExists')->willReturn(true);
		$this->fetcher->method('getViewData')->willReturn(['just', 'strings']);

		$expected = new QueryResult([], 0, 20, 0);

		$this->pipeline->expects($this->once())
			->method('execute')
			->with([], $this->anything(), $this->anything())
			->willReturn($expected);

		$result = $this->service->query('my-view', []);

		$this->assertSame($expected, $result);
	}

	public function testHandlesStringKeyedArraysReindexing(): void
	{
		$this->fetcher->method('dataExists')->willReturn(true);
		$this->fetcher->method('getViewData')->willReturn([
			'key-a' => ['id' => '1', 'title' => 'First'],
			'key-b' => ['id' => '2', 'title' => 'Second'],
		]);

		$expected = new QueryResult([], 0, 20, 0);

		$this->pipeline->expects($this->once())
			->method('execute')
			->with(
				$this->callback(function (array $items): bool {
					// Should be re-indexed to sequential 0, 1
					return array_keys($items) === [0, 1]
						&& $items[0]['id'] === '1'
						&& $items[1]['id'] === '2';
				}),
				$this->anything(),
				$this->anything(),
			)
			->willReturn($expected);

		$this->service->query('my-view', []);
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
