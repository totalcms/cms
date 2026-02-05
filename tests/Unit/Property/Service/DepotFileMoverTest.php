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
use TotalCMS\Domain\Property\Service\DepotFileMover;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

class DepotFileMoverTest extends TestCase
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

	public function testMovesFileBetweenFolders(): void
	{
		$depotData = new DepotData([
			'files' => [
				[
					'name'  => 'source',
					'mime'  => 'folder',
					'files' => [
						['name' => 'document.pdf', 'mime' => 'application/pdf', 'size' => 1024],
					],
				],
				[
					'name'  => 'destination',
					'mime'  => 'folder',
					'files' => [],
				],
			],
		]);

		$this->mockStorage->method('moveFile')->willReturn(true);
		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$mover  = $this->createMover();
		$result = $mover->moveFile('blog', 'post-1', 'files', 'document.pdf', 'source', 'destination');

		$this->assertTrue($result);
	}

	public function testReturnsFalseWhenStorageMoveFails(): void
	{
		$depotData = new DepotData([
			'files' => [
				['name' => 'file.txt', 'mime' => 'text/plain', 'size' => 100],
			],
		]);

		$this->mockStorage->method('moveFile')->willReturn(false);
		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);

		$mover  = $this->createMover();
		$result = $mover->moveFile('blog', 'post-1', 'files', 'file.txt', '', 'new-folder');

		$this->assertFalse($result);
	}

	public function testThrowsExceptionForNonExistentObject(): void
	{
		$this->mockObjectFetcher->method('existsObject')->willReturn(false);

		$mover = $this->createMover();

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('does not exist');

		$mover->moveFile('blog', 'missing-id', 'files', 'file.txt', '', 'folder');
	}

	public function testThrowsExceptionForNonDepotProperty(): void
	{
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		// Return FileData instead of DepotData
		$this->mockPropFetcher->method('fetchProperty')
			->willReturn(new FileData(['name' => 'file.txt', 'mime' => 'text/plain']));

		$mover = $this->createMover();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Expected instance of DepotData');

		$mover->moveFile('blog', 'post-1', 'files', 'file.txt', '', 'folder');
	}

	public function testCreatesDestinationFolderIfNotExists(): void
	{
		$depotData = new DepotData([
			'files' => [
				['name' => 'file.txt', 'mime' => 'text/plain', 'size' => 100],
			],
		]);

		$this->mockStorage->method('moveFile')->willReturn(true);
		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$mover  = $this->createMover();
		$result = $mover->moveFile('blog', 'post-1', 'files', 'file.txt', '', 'new-folder');

		$this->assertTrue($result);
	}

	public function testMovesFileFromRootToSubfolder(): void
	{
		$depotData = new DepotData([
			'files' => [
				['name' => 'root-file.pdf', 'mime' => 'application/pdf', 'size' => 500],
				['name' => 'archive', 'mime' => 'folder', 'files' => []],
			],
		]);

		$this->mockStorage->method('moveFile')->willReturn(true);
		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$mover  = $this->createMover();
		$result = $mover->moveFile('blog', 'post-1', 'files', 'root-file.pdf', '', 'archive');

		$this->assertTrue($result);
	}

	private function createMover(): DepotFileMover
	{
		return new DepotFileMover(
			$this->mockStorage,
			$this->mockPropFetcher,
			$this->mockObjectPatcher,
			$this->mockObjectFetcher
		);
	}
}
