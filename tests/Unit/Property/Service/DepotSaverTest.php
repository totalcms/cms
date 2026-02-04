<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\DepotSaver;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Factory\LoggerFactory;

class DepotSaverTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $mockStorage;
	private \PHPUnit\Framework\MockObject\MockObject $mockPropFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectSaver;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectPatcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockLoggerFactory;

	protected function setUp(): void
	{
		$this->mockStorage       = $this->createMock(PropertyRepository::class);
		$this->mockPropFetcher   = $this->createMock(PropertyFetcher::class);
		$this->mockObjectSaver   = $this->createMock(ObjectSaver::class);
		$this->mockObjectPatcher = $this->createMock(ObjectPatcher::class);
		$this->mockObjectFetcher = $this->createMock(ObjectFetcher::class);
		$this->mockLoggerFactory = $this->createMock(LoggerFactory::class);
	}

	public function testHasDepotType(): void
	{
		$saver = $this->createDepotSaver();
		$this->assertEquals('depot', $saver->type);
	}

	public function testSavesFileToRootOfExistingObject(): void
	{
		$depotData = new DepotData(['files' => []]);

		$this->mockStorage->method('saveFile')->willReturn([
			'name' => 'upload.pdf',
			'mime' => 'application/pdf',
			'size' => 1024,
		]);
		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$saver  = $this->createDepotSaver();
		$result = $saver->save('blog', 'post-1', 'files', '/tmp/upload.pdf');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testSavesFileToSubpath(): void
	{
		$depotData = new DepotData([
			'files' => [
				['name' => 'documents', 'mime' => 'folder', 'files' => []],
			],
		]);

		$this->mockStorage->method('saveFile')->willReturn([
			'name' => 'report.pdf',
			'mime' => 'application/pdf',
			'size' => 2048,
		]);
		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$saver  = $this->createDepotSaver();
		$result = $saver->save('blog', 'post-1', 'files', '/tmp/report.pdf', 'documents');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testCreatesObjectIfNotExists(): void
	{
		$this->mockStorage->method('saveFile')->willReturn([
			'name' => 'new-file.txt',
			'mime' => 'text/plain',
			'size' => 100,
		]);
		$this->mockPropFetcher->method('fetchProperty')->willReturn(new DepotData());
		$this->mockObjectFetcher->method('existsObject')->willReturn(false);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('new-post', []));

		$saver  = $this->createDepotSaver();
		$result = $saver->save('blog', 'new-post', 'files', '/tmp/new-file.txt');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testThrowsForNonDepotProperty(): void
	{
		$this->mockStorage->method('saveFile')->willReturn([
			'name' => 'file.txt',
			'mime' => 'text/plain',
			'size' => 100,
		]);
		// Return FileData instead of DepotData
		$this->mockPropFetcher->method('fetchProperty')
			->willReturn(new FileData(['name' => 'file.txt', 'mime' => 'text/plain']));
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);

		$saver = $this->createDepotSaver();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Expected instance of DepotData');

		$saver->save('blog', 'post-1', 'files', '/tmp/file.txt');
	}

	public function testAddsFileToExistingFiles(): void
	{
		$depotData = new DepotData([
			'files' => [
				['name' => 'existing.txt', 'mime' => 'text/plain', 'size' => 50],
			],
		]);

		$this->mockStorage->method('saveFile')->willReturn([
			'name' => 'new-file.pdf',
			'mime' => 'application/pdf',
			'size' => 200,
		]);
		$this->mockPropFetcher->method('fetchProperty')->willReturn($depotData);
		$this->mockObjectFetcher->method('existsObject')->willReturn(true);
		$this->mockObjectPatcher->method('patchObject')->willReturn(new ObjectData('post-1', []));

		$saver  = $this->createDepotSaver();
		$result = $saver->save('blog', 'post-1', 'files', '/tmp/new-file.pdf');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	private function createDepotSaver(): DepotSaver
	{
		return new DepotSaver(
			$this->mockStorage,
			$this->mockPropFetcher,
			$this->mockObjectSaver,
			$this->mockObjectPatcher,
			$this->mockObjectFetcher,
			$this->mockLoggerFactory
		);
	}
}
