<?php

namespace Tests\Unit\Domain\DataView\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\DataView\Service\DataViewFilter;
use TotalCMS\Domain\Query\Service\ObjectFilter;

final class DataViewFilterTest extends TestCase
{
	private MockObject&DataViewFetcher $fetcher;
	private DataViewFilter $filter;

	protected function setUp(): void
	{
		$this->fetcher = $this->createMock(DataViewFetcher::class);
		$this->filter  = new DataViewFilter($this->fetcher, new ObjectFilter());
	}

	// --- fetchFilteredViewData ---

	public function testFetchFilteredViewDataLoadsAndFilters(): void
	{
		$this->fetcher->method('getViewData')
			->with('my-view')
			->willReturn([
				['id' => '1', 'published' => true, 'draft' => false],
				['id' => '2', 'published' => true, 'draft' => true],
				['id' => '3', 'published' => false, 'draft' => false],
			]);

		$result = $this->filter->fetchFilteredViewData('my-view', [
			'include' => 'published:true',
			'exclude' => 'draft:true',
		]);

		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}

	public function testFetchFilteredViewDataWithNoFiltersReturnsAll(): void
	{
		$data = [
			['id' => '1', 'title' => 'First'],
			['id' => '2', 'title' => 'Second'],
		];

		$this->fetcher->method('getViewData')
			->with('my-view')
			->willReturn($data);

		$result = $this->filter->fetchFilteredViewData('my-view');

		$this->assertCount(2, $result);
		$this->assertSame($data, $result);
	}

	public function testFetchFilteredViewDataWithEmptyDataReturnsEmpty(): void
	{
		$this->fetcher->method('getViewData')
			->with('empty-view')
			->willReturn([]);

		$result = $this->filter->fetchFilteredViewData('empty-view', [
			'include' => 'published:true',
		]);

		$this->assertSame([], $result);
	}

	// --- isFilterable guard ---

	public function testSkipsFilteringForFlatStringArray(): void
	{
		$data = ['apple', 'banana', 'cherry'];

		$this->fetcher->method('getViewData')
			->with('flat-view')
			->willReturn($data);

		$result = $this->filter->fetchFilteredViewData('flat-view', [
			'include' => 'status:active',
		]);

		// Should return data as-is without attempting to filter
		$this->assertSame($data, $result);
	}

	public function testSkipsFilteringForFlatIntArray(): void
	{
		$data = [1, 2, 3, 4, 5];

		$this->fetcher->method('getViewData')
			->with('numbers-view')
			->willReturn($data);

		$result = $this->filter->fetchFilteredViewData('numbers-view', [
			'include' => 'value:true',
		]);

		$this->assertSame($data, $result);
	}

	public function testSkipsFilteringForListOfLists(): void
	{
		$data = [['a', 'b'], ['c', 'd']];

		$this->fetcher->method('getViewData')
			->with('list-view')
			->willReturn($data);

		$result = $this->filter->fetchFilteredViewData('list-view', [
			'include' => 'published:true',
		]);

		// array_is_list returns true for sequential arrays, so not filterable
		$this->assertSame($data, $result);
	}

	public function testFiltersAssociativeArrayData(): void
	{
		$this->fetcher->method('getViewData')
			->with('object-view')
			->willReturn([
				['id' => '1', 'status' => 'active'],
				['id' => '2', 'status' => 'inactive'],
				['id' => '3', 'status' => 'active'],
			]);

		$result = $this->filter->fetchFilteredViewData('object-view', [
			'include' => 'status:active',
		]);

		$this->assertCount(2, $result);
		$this->assertSame('1', $result[0]['id']);
		$this->assertSame('3', $result[1]['id']);
	}

	public function testExcludeFilterOnViewData(): void
	{
		$this->fetcher->method('getViewData')
			->with('blog-view')
			->willReturn([
				['id' => '1', 'draft' => false, 'archived' => false],
				['id' => '2', 'draft' => true, 'archived' => false],
				['id' => '3', 'draft' => false, 'archived' => true],
				['id' => '4', 'draft' => false, 'archived' => false],
			]);

		$result = $this->filter->fetchFilteredViewData('blog-view', [
			'exclude' => 'draft:true,archived:true',
		]);

		$this->assertCount(2, $result);
		$this->assertSame('1', $result[0]['id']);
		$this->assertSame('4', $result[1]['id']);
	}

	// --- sort option ---

	public function testSortViewDataAscending(): void
	{
		$this->fetcher->method('getViewData')
			->with('my-view')
			->willReturn([
				['id' => '2', 'title' => 'Banana'],
				['id' => '1', 'title' => 'Apple'],
				['id' => '3', 'title' => 'Cherry'],
			]);

		$result = $this->filter->fetchFilteredViewData('my-view', ['sort' => 'title']);

		$this->assertSame('Apple', $result[0]['title']);
		$this->assertSame('Banana', $result[1]['title']);
		$this->assertSame('Cherry', $result[2]['title']);
	}

	public function testSortViewDataDescending(): void
	{
		$this->fetcher->method('getViewData')
			->with('my-view')
			->willReturn([
				['id' => '2', 'title' => 'Banana'],
				['id' => '1', 'title' => 'Apple'],
				['id' => '3', 'title' => 'Cherry'],
			]);

		$result = $this->filter->fetchFilteredViewData('my-view', ['sort' => '-title']);

		$this->assertSame('Cherry', $result[0]['title']);
		$this->assertSame('Banana', $result[1]['title']);
		$this->assertSame('Apple', $result[2]['title']);
	}

	public function testFilterAndSortViewData(): void
	{
		$this->fetcher->method('getViewData')
			->with('my-view')
			->willReturn([
				['id' => '1', 'title' => 'Cherry', 'status' => 'active'],
				['id' => '2', 'title' => 'Apple', 'status' => 'inactive'],
				['id' => '3', 'title' => 'Banana', 'status' => 'active'],
			]);

		$result = $this->filter->fetchFilteredViewData('my-view', [
			'include' => 'status:active',
			'sort'    => 'title',
		]);

		$this->assertCount(2, $result);
		$this->assertSame('Banana', $result[0]['title']);
		$this->assertSame('Cherry', $result[1]['title']);
	}

	// --- getViewData (raw passthrough) ---

	public function testGetViewDataDelegates(): void
	{
		$expected = [['id' => '1', 'name' => 'Test']];

		$this->fetcher->expects($this->once())
			->method('getViewData')
			->with('my-view')
			->willReturn($expected);

		$this->assertSame($expected, $this->filter->getViewData('my-view'));
	}
}
