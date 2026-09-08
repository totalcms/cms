<?php

namespace Tests\Unit\Domain\DataView\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\DataView\Data\DataViewData;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;

final class DataViewListerTest extends TestCase
{
	private DataViewLister $lister;
	private MockObject&CollectionFetcher $collectionFetcher;
	private MockObject&IndexReader $indexReader;

	protected function setUp(): void
	{
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->indexReader       = $this->createMock(IndexReader::class);

		$this->lister = new DataViewLister(
			$this->collectionFetcher,
			$this->indexReader,
		);
	}

	public function testListViewsReturnsObjectsArray(): void
	{
		$objects = [
			['id' => 'view-1', 'title' => 'View One'],
			['id' => 'view-2', 'title' => 'View Two'],
		];

		$this->collectionFetcher->method('fetchOrCreateReserved')
			->with(DataViewData::COLLECTION_ID)
			->willReturn(null);

		$this->indexReader->expects($this->once())
			->method('fetchIndex')
			->with(DataViewData::COLLECTION_ID)
			->willReturn(new IndexData($objects));

		$result = $this->lister->listViews();

		$this->assertSame($objects, $result);
	}

	public function testListViewsCreatesCollectionIfMissing(): void
	{
		$this->collectionFetcher->expects($this->once())
			->method('fetchOrCreateReserved')
			->with(DataViewData::COLLECTION_ID)
			->willReturn(null);

		$this->indexReader->method('fetchIndex')
			->willReturn(new IndexData([]));

		$this->lister->listViews();
	}

	public function testEnsureCollectionSkipsCreationWhenExists(): void
	{
		$this->collectionFetcher->expects($this->once())
			->method('fetchOrCreateReserved')
			->with(DataViewData::COLLECTION_ID)
			->willReturn(null);

		$this->lister->ensureCollection();
	}

	public function testEnsureCollectionCreatesReservedCollectionWhenMissing(): void
	{
		$this->collectionFetcher->expects($this->once())
			->method('fetchOrCreateReserved')
			->with(DataViewData::COLLECTION_ID)
			->willReturn(null);

		$this->lister->ensureCollection();
	}
}
