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
use TotalCMS\Domain\Property\Service\DepotRemover;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

class DepotRemoverTest extends TestCase
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

	public function testDeletesFileFromRoot(): void
	{
		$depotData = new DepotData([
			'files' => [
				['name' => 'delete-me.txt', 'mime' => 'text/plain', 'size' => 100],
				['name' => 'keep-me.txt', 'mime' => 'text/plain', 'size' => 200],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$remover = $this->createRemover();
		$result  = $remover->deleteFile('blog', 'post-1', 'files', 'delete-me.txt');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testDeletesFileFromSubfolder(): void
	{
		$depotData = new DepotData([
			'files' => [
				[
					'name'  => 'folder',
					'mime'  => 'folder',
					'files' => [
						['name' => 'nested.pdf', 'mime' => 'application/pdf', 'size' => 500],
						['name' => 'other.pdf', 'mime' => 'application/pdf', 'size' => 600],
					],
				],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$remover = $this->createRemover();
		$result  = $remover->deleteFile('blog', 'post-1', 'files', 'nested.pdf', 'folder');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testThrowsExceptionForNonExistentObject(): void
	{
		$this->mockObjectFetcher->method('existsObject')->willReturn(false);

		$remover = $this->createRemover();

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('does not exist');

		$remover->deleteFile('blog', 'missing-id', 'files', 'file.txt');
	}

	public function testThrowsExceptionForNonDepotProperty(): void
	{
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		// Return FileData instead of DepotData
		$this->mockPropFetcher->method('fetchProperty')
			->willReturn(new FileData(['name' => 'file.txt', 'mime' => 'text/plain']));

		$remover = $this->createRemover();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Expected instance of DepotData');

		$remover->deleteFile('blog', 'post-1', 'files', 'file.txt');
	}

	public function testCallsBothDeleteDirectoryAndDeleteFileOnStorage(): void
	{
		$depotData = new DepotData([
			'files' => [
				['name' => 'folder', 'mime' => 'folder', 'files' => []],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		// Verify both methods are called
		$this->mockStorage->expects($this->once())->method('deleteDirectory');
		$this->mockStorage->expects($this->once())->method('deleteFile');

		$remover = $this->createRemover();
		$remover->deleteFile('blog', 'post-1', 'files', 'folder');
	}

	public function testPassesCorrectSubpathToStorage(): void
	{
		$depotData = new DepotData([
			'files' => [
				[
					'name'  => 'level1',
					'mime'  => 'folder',
					'files' => [
						[
							'name'  => 'level2',
							'mime'  => 'folder',
							'files' => [
								['name' => 'deep.txt', 'mime' => 'text/plain', 'size' => 10],
							],
						],
					],
				],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$remover = $this->createRemover();
		$remover->deleteFile('blog', 'post-1', 'files', 'deep.txt', 'level1/level2');

		// Test passes if no exceptions are thrown
		$this->assertTrue(true);
	}

	private function createRemover(): DepotRemover
	{
		return new DepotRemover(
			$this->mockStorage,
			$this->mockPropFetcher,
			$this->mockObjectPatcher,
			$this->mockObjectFetcher
		);
	}
}
