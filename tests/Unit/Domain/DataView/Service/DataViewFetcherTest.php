<?php

namespace Tests\Unit\Domain\DataView\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\DataView\Repository\DataViewRepository;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;

final class DataViewFetcherTest extends TestCase
{
	private DataViewFetcher $fetcher;
	private MockObject&DataViewRepository $repository;

	protected function setUp(): void
	{
		$this->repository = $this->createMock(DataViewRepository::class);
		$this->fetcher    = new DataViewFetcher($this->repository);
	}

	public function testDataExistsDelegatesToRepository(): void
	{
		$this->repository->expects($this->once())
			->method('dataExists')
			->with('view-1')
			->willReturn(true);

		$this->assertTrue($this->fetcher->dataExists('view-1'));
	}

	public function testGetViewDataDelegatesToRepository(): void
	{
		$expected = ['items' => [1, 2, 3]];

		$this->repository->expects($this->once())
			->method('fetchData')
			->with('view-1')
			->willReturn($expected);

		$this->assertSame($expected, $this->fetcher->getViewData('view-1'));
	}
}
