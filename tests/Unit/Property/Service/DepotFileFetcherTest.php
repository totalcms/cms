<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\DepotFileFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

class DepotFileFetcherTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $mockStorage;
	private \PHPUnit\Framework\MockObject\MockObject $mockPropFetcher;

	protected function setUp(): void
	{
		$this->mockStorage     = $this->createMock(PropertyRepository::class);
		$this->mockPropFetcher = $this->createMock(PropertyFetcher::class);
	}

	public function testFetchFileRetrievesFileFromRoot(): void
	{
		$depotData = new DepotData([
			'files' => [
				['name' => 'document.pdf', 'mime' => 'application/pdf', 'size' => 1024],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')
			->willReturn($depotData);

		$fetcher = $this->createFetcher();
		$result  = $fetcher->fetchFile('blog', 'post-1', 'files', 'document.pdf');

		$this->assertInstanceOf(FileData::class, $result);
		$this->assertEquals('document.pdf', $result->name);
		$this->assertEquals(1024, $result->size);
	}

	public function testFetchFileRetrievesFileFromSubpath(): void
	{
		$depotData = new DepotData([
			'files' => [
				[
					'name'  => 'documents',
					'mime'  => 'folder',
					'files' => [
						['name' => 'report.pdf', 'mime' => 'application/pdf', 'size' => 2048],
					],
				],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')
			->willReturn($depotData);

		$fetcher = $this->createFetcher();
		$result  = $fetcher->fetchFile('blog', 'post-1', 'files', 'report.pdf', 'documents');

		$this->assertInstanceOf(FileData::class, $result);
		$this->assertEquals('report.pdf', $result->name);
	}

	public function testFetchFileThrowsForNonDepotProperty(): void
	{
		// Return FileData instead of DepotData to trigger the check
		$this->mockPropFetcher->method('fetchProperty')
			->willReturn(new FileData(['name' => 'file.txt', 'mime' => 'text/plain']));

		$fetcher = $this->createFetcher();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unable to retrieve depot data');

		$fetcher->fetchFile('blog', 'post-1', 'files', 'file.txt');
	}

	public function testFetchFileThrowsForNonExistentFile(): void
	{
		$depotData = new DepotData([
			'files' => [
				['name' => 'existing.txt', 'mime' => 'text/plain', 'size' => 100],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')
			->willReturn($depotData);

		$fetcher = $this->createFetcher();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unable to retrieve file data');

		$fetcher->fetchFile('blog', 'post-1', 'files', 'missing.txt');
	}

	public function testFetchFileReturnsFileWithAllMetadata(): void
	{
		$depotData = new DepotData([
			'files' => [
				[
					'name'      => 'file.pdf',
					'mime'      => 'application/pdf',
					'size'      => 5000,
					'download'  => 'custom-download.pdf',
					'comments'  => 'Important document',
					'tags'      => ['work', 'urgent'],
					'protected' => true,
				],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')
			->willReturn($depotData);

		$fetcher = $this->createFetcher();
		$result  = $fetcher->fetchFile('blog', 'post-1', 'files', 'file.pdf');

		$this->assertEquals('file.pdf', $result->name);
		$this->assertEquals('application/pdf', $result->mime);
		$this->assertEquals(5000, $result->size);
		$this->assertEquals('custom-download.pdf', $result->download);
		$this->assertEquals('Important document', $result->comments);
		$this->assertTrue($result->protected);
	}

	public function testFetchFileFindsDeeplyNestedFile(): void
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
								[
									'name'  => 'level3',
									'mime'  => 'folder',
									'files' => [
										['name' => 'deep.txt', 'mime' => 'text/plain', 'size' => 10],
									],
								],
							],
						],
					],
				],
			],
		]);

		$this->mockPropFetcher->method('fetchProperty')
			->willReturn($depotData);

		$fetcher = $this->createFetcher();
		$result  = $fetcher->fetchFile('blog', 'post-1', 'files', 'deep.txt', 'level1/level2/level3');

		$this->assertInstanceOf(FileData::class, $result);
		$this->assertEquals('deep.txt', $result->name);
	}

	public function testFileExistsDelegatesToStorage(): void
	{
		$this->mockStorage->method('fileExists')
			->with('blog', 'post-1', 'files', 'document.pdf', null)
			->willReturn(true);

		$fetcher = $this->createFetcher();
		$result  = $fetcher->fileExists('blog', 'post-1', 'files', 'document.pdf');

		$this->assertTrue($result);
	}

	public function testFileExistsWithSubpath(): void
	{
		$this->mockStorage->method('fileExists')
			->with('blog', 'post-1', 'files', 'nested.txt', 'subfolder')
			->willReturn(true);

		$fetcher = $this->createFetcher();
		$result  = $fetcher->fileExists('blog', 'post-1', 'files', 'nested.txt', 'subfolder');

		$this->assertTrue($result);
	}

	public function testFileExistsReturnsFalseForMissingFile(): void
	{
		$this->mockStorage->method('fileExists')
			->willReturn(false);

		$fetcher = $this->createFetcher();
		$result  = $fetcher->fileExists('blog', 'post-1', 'files', 'missing.pdf');

		$this->assertFalse($result);
	}

	public function testStreamFileDelegatesToStorage(): void
	{
		$fakeStream = fopen('php://memory', 'r');

		$this->mockStorage->method('streamFile')
			->willReturn($fakeStream);

		$fetcher = $this->createFetcher();
		$result  = $fetcher->streamFile('blog', 'post-1', 'files', 'video.mp4');

		$this->assertSame($fakeStream, $result);

		fclose($fakeStream);
	}

	private function createFetcher(): DepotFileFetcher
	{
		return new DepotFileFetcher($this->mockStorage, $this->mockPropFetcher);
	}
}
