<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Property\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\DepotFileFetcher;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Property\Service\PropertyFileResolver;

// One resolver decides what a `{path}` under a file property points at — a
// depot entry, or a file nested in a card or deck item — and hands back a
// PropertyFile the download and stream actions serve without knowing which.
// The dispatch heuristic used to be duplicated in DownloadFileFromDepotAction
// and StreamFileFromDepotAction (and the stream copy 500'd on a depot
// subfolder where the download copy 404'd).
final class PropertyFileResolverTest extends TestCase
{
	private MockObject $depotFetcher;
	private MockObject $fileFetcher;
	private PropertyFileResolver $resolver;

	protected function setUp(): void
	{
		$this->depotFetcher = $this->createMock(DepotFileFetcher::class);
		$this->fileFetcher  = $this->createMock(FileFetcher::class);
		$this->resolver     = new PropertyFileResolver(
			$this->fileFetcher,
			$this->depotFetcher,
			$this->createMock(PropertyFetcher::class),
			$this->createMock(ObjectUpdater::class),
		);
	}

	public function testACardChildPathIsANestedFile(): void
	{
		$this->fileFetcher->expects($this->once())->method('isNestedDirectory')->with('blog', 'post-1', 'mycard', 'file')->willReturn(true);
		$this->fileFetcher->expects($this->once())->method('fetchFile')->with('blog', 'post-1', 'mycard', 'file')->willReturn(new FileData(['name' => 'document.pdf']));
		$this->fileFetcher->expects($this->once())->method('fileExists')->with('blog', 'post-1', 'mycard', 'file')->willReturn(true);
		$this->depotFetcher->expects($this->never())->method('fileExists');

		$file = $this->resolver->depotOrNested('blog', 'post-1', 'mycard', 'file', null);

		$this->assertSame('document.pdf', $file->name);
		$this->assertTrue($file->exists());
	}

	public function testADeckChildMultiSegmentPathIsANestedFile(): void
	{
		$this->fileFetcher->method('isNestedDirectory')->with('blog', 'post-1', 'mydeck', 'item-3/file')->willReturn(true);
		$this->fileFetcher->method('fetchFile')->with('blog', 'post-1', 'mydeck', 'item-3/file')->willReturn(new FileData(['name' => 'deck-doc.pdf']));
		$this->fileFetcher->expects($this->once())->method('fileExists')->with('blog', 'post-1', 'mydeck', 'item-3/file')->willReturn(false);

		$file = $this->resolver->depotOrNested('blog', 'post-1', 'mydeck', 'item-3/file', null);

		$this->assertFalse($file->exists());
	}

	public function testADepotPathIsADepotEntryNamedByTheWholePath(): void
	{
		$this->fileFetcher->method('isNestedDirectory')->willReturn(false);
		$this->fileFetcher->expects($this->never())->method('fetchFile');
		$this->depotFetcher->expects($this->once())->method('fileExists')->with('blog', 'post-1', 'depot', 'report.pdf', null)->willReturn(true);

		$file = $this->resolver->depotOrNested('blog', 'post-1', 'depot', 'report.pdf', null);

		$this->assertSame('report.pdf', $file->name);
		$this->assertTrue($file->exists());
	}

	public function testTheDepotSubfolderComesFromTheQueryAndTheNameIsUrlDecoded(): void
	{
		$this->fileFetcher->method('isNestedDirectory')->willReturn(false);
		$this->depotFetcher->expects($this->once())->method('fileExists')->with('blog', 'post-1', 'depot', 'my report (1).pdf', 'reports/2026')->willReturn(true);

		$file = $this->resolver->depotOrNested('blog', 'post-1', 'depot', 'my+report%20(1).pdf', 'reports/2026');

		$this->assertTrue($file->exists());
	}

	public function testADepotSubfolderThatLooksNestedIsAMissNotAServerFault(): void
	{
		// isNestedDirectory() is a heuristic: a plain depot subfolder is also a
		// real directory under the property. There is no FileData behind it,
		// so fetching throws — that is "not found", for both download and
		// stream.
		$this->fileFetcher->method('isNestedDirectory')->willReturn(true);
		$this->fileFetcher->method('fetchFile')->willThrowException(new \RuntimeException('no file data'));
		$this->fileFetcher->expects($this->never())->method('fileExists');

		$file = $this->resolver->depotOrNested('blog', 'post-1', 'depot', 'subfolder', null);

		$this->assertFalse($file->exists());
	}

	public function testAPlainFilePropertyIsResolvedDirectly(): void
	{
		$this->fileFetcher->expects($this->once())->method('fileExists')->with('blog', 'post-1', 'attachment')->willReturn(true);
		$this->fileFetcher->expects($this->once())->method('fileSize')->with('blog', 'post-1', 'attachment')->willReturn(42);

		$file = $this->resolver->file('blog', 'post-1', 'attachment');

		$this->assertTrue($file->exists());
		$this->assertSame(42, $file->size());
	}
}
