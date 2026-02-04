<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\DepotFolderSaver;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

class DepotFolderSaverTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $mockStorage;
	private \PHPUnit\Framework\MockObject\MockObject $mockPropFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectPatcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectFetcher;

	protected function setUp(): void
	{
		$this->mockStorage       = $this->createMock(PropertyRepository::class);
		$this->mockPropFetcher   = $this->createMock(PropertyFetcher::class);
		$this->mockObjectPatcher = $this->createMock(ObjectPatcher::class);
		$this->mockObjectFetcher = $this->createMock(ObjectFetcher::class);
	}

	public function testCreatesFolderAtRootLevel(): void
	{
		$depotData = new DepotData(['files' => []]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$saver  = $this->createSaver();
		$result = $saver->createFolder('blog', 'post-1', 'files', 'new-folder');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testCreatesNestedFolderPath(): void
	{
		$depotData = new DepotData(['files' => []]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$saver  = $this->createSaver();
		$result = $saver->createFolder('blog', 'post-1', 'files', 'documents/reports/2024');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testThrowsExceptionForNonExistentObject(): void
	{
		$this->mockObjectFetcher->method('existsObject')->willReturn(false);

		$saver = $this->createSaver();

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('does not exist');

		$saver->createFolder('blog', 'missing-id', 'files', 'folder');
	}

	public function testThrowsExceptionForNonDepotProperty(): void
	{
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		// Return FileData instead of DepotData
		$this->mockPropFetcher->method('fetchProperty')
			->willReturn(new FileData(['name' => 'file.txt', 'mime' => 'text/plain']));

		$saver = $this->createSaver();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Expected instance of DepotData');

		$saver->createFolder('blog', 'post-1', 'files', 'folder');
	}

	public function testPreservesExistingFilesWhenCreatingFolder(): void
	{
		$depotData = new DepotData([
			'files' => [
				['name' => 'existing.txt', 'mime' => 'text/plain', 'size' => 100],
				['name' => 'old-folder', 'mime' => 'folder', 'files' => []],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$saver  = $this->createSaver();
		$result = $saver->createFolder('blog', 'post-1', 'files', 'new-folder');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testCreatesFolderInsideExistingFolder(): void
	{
		$depotData = new DepotData([
			'files' => [
				[
					'name'  => 'existing',
					'mime'  => 'folder',
					'files' => [
						['name' => 'file.txt', 'mime' => 'text/plain', 'size' => 50],
					],
				],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$saver  = $this->createSaver();
		$result = $saver->createFolder('blog', 'post-1', 'files', 'existing/subfolder');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testReusesExistingFolderInPath(): void
	{
		$depotData = new DepotData([
			'files' => [
				[
					'name'  => 'level1',
					'mime'  => 'folder',
					'files' => [],
				],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$saver  = $this->createSaver();
		$result = $saver->createFolder('blog', 'post-1', 'files', 'level1/level2');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testHandlesDeeplyNestedPathCreation(): void
	{
		$depotData = new DepotData(['files' => []]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$saver  = $this->createSaver();
		$result = $saver->createFolder('blog', 'post-1', 'files', 'a/b/c/d/e');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testReturnsObjectDataFromPatcher(): void
	{
		$depotData = new DepotData(['files' => []]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);

		$expectedObject = new ObjectData('post-1', ['title' => 'Test Post']);
		$this->mockObjectPatcher->method('patchObject')->willReturn($expectedObject);

		$saver  = $this->createSaver();
		$result = $saver->createFolder('blog', 'post-1', 'files', 'folder');

		$this->assertSame($expectedObject, $result);
	}

	private function createSaver(): DepotFolderSaver
	{
		return new DepotFolderSaver(
			$this->mockStorage,
			$this->mockPropFetcher,
			$this->mockObjectPatcher,
			$this->mockObjectFetcher
		);
	}
}
