<?php

namespace Tests\Unit\Domain\DataView\Repository;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\DataView\Repository\DataViewRepository;
use TotalCMS\Domain\Storage\StorageAdapterInterface;

final class DataViewRepositoryTest extends TestCase
{
	private DataViewRepository $repository;
	private MockObject&StorageAdapterInterface $filesystem;
	private MockObject&CacheManager $cacheManager;

	protected function setUp(): void
	{
		$this->filesystem   = $this->createMock(StorageAdapterInterface::class);
		$this->cacheManager = $this->createMock(CacheManager::class);
		$this->repository   = new DataViewRepository($this->filesystem, $this->cacheManager);
	}

	public function testSaveDataWritesJsonAndClearsCache(): void
	{
		$data = ['key' => 'value', 'count' => 42];

		$this->filesystem->expects($this->once())
			->method('write')
			->with(
				'.system/dataviews/test-view/data.json',
				json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
			);

		$this->cacheManager->expects($this->once())
			->method('clearComputedData')
			->with('dataview:test-view');

		$this->repository->saveData('test-view', $data);
	}

	public function testFetchDataReturnsCachedDataWhenAvailable(): void
	{
		$cachedData = ['cached' => true];

		$this->cacheManager->expects($this->once())
			->method('getComputedData')
			->with('dataview:my-view')
			->willReturn($cachedData);

		$this->filesystem->expects($this->never())
			->method('fileExists');

		$result = $this->repository->fetchData('my-view');

		$this->assertSame($cachedData, $result);
	}

	public function testFetchDataReadsFromFilesystemOnCacheMiss(): void
	{
		$data = ['from' => 'disk'];

		$this->cacheManager->expects($this->once())
			->method('getComputedData')
			->with('dataview:my-view')
			->willReturn(null);

		$this->filesystem->expects($this->once())
			->method('fileExists')
			->with('.system/dataviews/my-view/data.json')
			->willReturn(true);

		$this->filesystem->expects($this->once())
			->method('read')
			->with('.system/dataviews/my-view/data.json')
			->willReturn(json_encode($data));

		$this->cacheManager->expects($this->once())
			->method('storeComputedData')
			->with('dataview:my-view', $data, 14400);

		$result = $this->repository->fetchData('my-view');

		$this->assertSame($data, $result);
	}

	public function testFetchDataReturnsEmptyArrayWhenFileDoesNotExist(): void
	{
		$this->cacheManager->method('getComputedData')->willReturn(null);

		$this->filesystem->expects($this->once())
			->method('fileExists')
			->with('.system/dataviews/missing/data.json')
			->willReturn(false);

		$result = $this->repository->fetchData('missing');

		$this->assertSame([], $result);
	}

	public function testFetchDataReturnsEmptyArrayWhenFileIsEmpty(): void
	{
		$this->cacheManager->method('getComputedData')->willReturn(null);

		$this->filesystem->method('fileExists')->willReturn(true);
		$this->filesystem->method('read')->willReturn('');

		$result = $this->repository->fetchData('empty-view');

		$this->assertSame([], $result);
	}

	public function testDataExistsReturnsTrueWhenFileExists(): void
	{
		$this->filesystem->expects($this->once())
			->method('fileExists')
			->with('.system/dataviews/my-view/data.json')
			->willReturn(true);

		$this->assertTrue($this->repository->dataExists('my-view'));
	}

	public function testDataExistsReturnsFalseWhenFileMissing(): void
	{
		$this->filesystem->expects($this->once())
			->method('fileExists')
			->with('.system/dataviews/no-view/data.json')
			->willReturn(false);

		$this->assertFalse($this->repository->dataExists('no-view'));
	}

	public function testDeleteDataDeletesDirectoryAndClearsCache(): void
	{
		$this->filesystem->expects($this->once())
			->method('directoryExists')
			->with('.system/dataviews/old-view')
			->willReturn(true);

		$this->filesystem->expects($this->once())
			->method('deleteDirectory')
			->with('.system/dataviews/old-view');

		$this->cacheManager->expects($this->once())
			->method('clearComputedData')
			->with('dataview:old-view');

		$this->repository->deleteData('old-view');
	}

	public function testDeleteDataDoesNothingWhenDirectoryDoesNotExist(): void
	{
		$this->filesystem->expects($this->once())
			->method('directoryExists')
			->with('.system/dataviews/gone')
			->willReturn(false);

		$this->filesystem->expects($this->never())
			->method('deleteDirectory');

		$this->cacheManager->expects($this->never())
			->method('clearComputedData');

		$this->repository->deleteData('gone');
	}
}
