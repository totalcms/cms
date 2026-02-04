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
use TotalCMS\Domain\Property\Service\DepotFolderRenamer;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

class DepotFolderRenamerTest extends TestCase
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

	public function testRenamesFolderAtRootLevel(): void
	{
		$depotData = new DepotData([
			'files' => [
				[
					'name'  => 'old-folder',
					'mime'  => 'folder',
					'files' => [
						['name' => 'file.txt', 'mime' => 'text/plain'],
					],
				],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$renamer = $this->createRenamer();
		$result  = $renamer->renameFolder('blog', 'post-1', 'files', 'old-folder', 'new-folder');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testThrowsExceptionForNonExistentObject(): void
	{
		$this->mockObjectFetcher->method('existsObject')->willReturn(false);

		$renamer = $this->createRenamer();

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('does not exist');

		$renamer->renameFolder('blog', 'missing-id', 'files', 'folder', 'new-name');
	}

	public function testThrowsExceptionForNonDepotProperty(): void
	{
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		// Return FileData instead of DepotData
		$this->mockPropFetcher->method('fetchProperty')
			->willReturn(new FileData(['name' => 'file.txt', 'mime' => 'text/plain']));

		$renamer = $this->createRenamer();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Expected instance of DepotData');

		$renamer->renameFolder('blog', 'post-1', 'files', 'folder', 'new-name');
	}

	public function testRenamesNestedFolder(): void
	{
		$depotData = new DepotData([
			'files' => [
				[
					'name'  => 'parent',
					'mime'  => 'folder',
					'files' => [
						[
							'name'  => 'child',
							'mime'  => 'folder',
							'files' => [],
						],
					],
				],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$renamer = $this->createRenamer();
		$result  = $renamer->renameFolder('blog', 'post-1', 'files', 'parent/child', 'renamed-child');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testPreservesFolderContentsAfterRename(): void
	{
		$depotData = new DepotData([
			'files' => [
				[
					'name'  => 'documents',
					'mime'  => 'folder',
					'files' => [
						['name' => 'report.pdf', 'mime' => 'application/pdf', 'size' => 1024],
						['name' => 'notes.txt', 'mime' => 'text/plain', 'size' => 256],
					],
				],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$renamer = $this->createRenamer();
		$result  = $renamer->renameFolder('blog', 'post-1', 'files', 'documents', 'docs');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	private function createRenamer(): DepotFolderRenamer
	{
		return new DepotFolderRenamer(
			$this->mockStorage,
			$this->mockPropFetcher,
			$this->mockObjectPatcher,
			$this->mockObjectFetcher
		);
	}
}
