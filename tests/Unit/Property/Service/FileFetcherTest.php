<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

/**
 * Locks in the read-side traversal that powers downloads/streams for nested
 * files. Top-level files defer to PropertyFetcher (legacy path); nested files
 * walk the parent's raw object data.
 */
final class FileFetcherTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $storage;
	private \PHPUnit\Framework\MockObject\MockObject $propFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private FileFetcher $fetcher;

	protected function setUp(): void
	{
		$this->storage       = $this->createMock(PropertyRepository::class);
		$this->propFetcher   = $this->createMock(PropertyFetcher::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);

		$this->fetcher = new FileFetcher($this->storage, $this->propFetcher, $this->objectFetcher);
	}

	/** @param array<string,mixed> $data */
	private function stubObject(array $data): void
	{
		$object = $this->createMock(ObjectData::class);
		$object->method('toArray')->willReturn($data);
		$this->objectFetcher->method('fetchObject')->willReturn($object);
	}

	// ──────────────────────────────────────────────────────────────────────
	// fetchFile
	// ──────────────────────────────────────────────────────────────────────

	public function testFetchFileTopLevelDelegatesToPropertyFetcher(): void
	{
		$file = new FileData(['name' => 'doc.pdf']);
		$this->propFetcher->expects($this->once())
			->method('fetchProperty')
			->with('blog', 'post-1', 'attachment')
			->willReturn($file);

		// Top-level walk should NOT touch the object fetcher.
		$this->objectFetcher->expects($this->never())->method('fetchObject');

		$result = $this->fetcher->fetchFile('blog', 'post-1', 'attachment');

		$this->assertSame($file, $result);
	}

	public function testFetchFileTopLevelThrowsWhenPropertyIsNotFileData(): void
	{
		// PropertyFetcher returning non-FileData (e.g. CardData on a misrouted
		// call) should fail loudly so DownloadAction returns 404.
		$this->propFetcher->method('fetchProperty')->willReturn(new \TotalCMS\Domain\Property\Data\CardData([], []));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unable to retrieve file data');

		$this->fetcher->fetchFile('blog', 'post-1', 'attachment');
	}

	public function testFetchFileCardChildWalksOneSegment(): void
	{
		$this->stubObject([
			'mycard' => [
				'file' => ['name' => 'document.pdf', 'size' => 1024],
				'text' => 'sibling preserved',
			],
		]);

		// Nested walk should NOT touch the property fetcher.
		$this->propFetcher->expects($this->never())->method('fetchProperty');

		$result = $this->fetcher->fetchFile('blog', 'post-1', 'mycard', 'file');

		$this->assertSame('document.pdf', $result->name);
		$this->assertSame(1024, $result->size);
	}

	public function testFetchFileDeckChildWalksTwoSegments(): void
	{
		$this->stubObject([
			'mydeck' => [
				'item-3' => [
					'file' => ['name' => 'deck-doc.pdf'],
				],
			],
		]);

		$result = $this->fetcher->fetchFile('blog', 'post-1', 'mydeck', 'item-3/file');

		$this->assertSame('deck-doc.pdf', $result->name);
	}

	public function testFetchFileNestedThrowsWhenIntermediateMissing(): void
	{
		$this->stubObject([
			'mydeck' => [
				'item-3' => ['file' => ['name' => 'doc.pdf']],
			],
		]);

		$this->expectException(\RuntimeException::class);
		// item-99 doesn't exist → cursor becomes null mid-walk.
		$this->fetcher->fetchFile('blog', 'post-1', 'mydeck', 'item-99/file');
	}

	public function testFetchFileNestedThrowsWhenLeafMissing(): void
	{
		$this->stubObject([
			'mycard' => ['text' => 'no file here'],
		]);

		$this->expectException(\RuntimeException::class);
		$this->fetcher->fetchFile('blog', 'post-1', 'mycard', 'file');
	}

	public function testFetchFileNestedThrowsWhenLeafIsNotArray(): void
	{
		$this->stubObject([
			'mycard' => ['file' => 'not-a-file-object'],
		]);

		$this->expectException(\RuntimeException::class);
		$this->fetcher->fetchFile('blog', 'post-1', 'mycard', 'file');
	}

	// ──────────────────────────────────────────────────────────────────────
	// fileExists / streamFile / fileSize — subpath threads through to storage
	// ──────────────────────────────────────────────────────────────────────

	public function testFileExistsThreadsSubpathToStorage(): void
	{
		$this->stubObject([
			'mycard' => ['file' => ['name' => 'document.pdf']],
		]);

		$this->storage->expects($this->once())
			->method('fileExists')
			->with('blog', 'post-1', 'mycard', 'document.pdf', 'file')
			->willReturn(true);

		$this->assertTrue($this->fetcher->fileExists('blog', 'post-1', 'mycard', 'file'));
	}

	public function testFileExistsTopLevelPassesNullSubpath(): void
	{
		$this->propFetcher->method('fetchProperty')->willReturn(new FileData(['name' => 'top.pdf']));

		$this->storage->expects($this->once())
			->method('fileExists')
			->with('blog', 'post-1', 'attachment', 'top.pdf', null)
			->willReturn(true);

		$this->fetcher->fileExists('blog', 'post-1', 'attachment');
	}

	public function testFileSizeThreadsSubpathToStorage(): void
	{
		$this->stubObject([
			'mydeck' => ['item-3' => ['file' => ['name' => 'deck.pdf']]],
		]);

		$this->storage->expects($this->once())
			->method('fileSize')
			->with('blog', 'post-1', 'mydeck', 'deck.pdf', 'item-3/file')
			->willReturn(2048);

		$this->assertSame(2048, $this->fetcher->fileSize('blog', 'post-1', 'mydeck', 'item-3/file'));
	}

	public function testStreamFileThreadsSubpathToStorage(): void
	{
		$this->stubObject([
			'mycard' => ['file' => ['name' => 'document.pdf']],
		]);

		$stream = fopen('php://memory', 'r');
		self::assertNotFalse($stream);

		$this->storage->expects($this->once())
			->method('streamFile')
			->with('blog', 'post-1', 'mycard', 'document.pdf', 'file')
			->willReturn($stream);

		$this->fetcher->streamFile('blog', 'post-1', 'mycard', 'file');

		fclose($stream);
	}
}
