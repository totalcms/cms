<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Index\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;

/**
 * The index is what every listing, query and admin page reads, and this is the
 * front door to it. The case worth pinning is the one nobody sees in
 * development: no index file on disk yet, so it has to be built before it can
 * be returned.
 */
final class IndexReaderTest extends TestCase
{
	public function testReturnsTheStoredIndexWhenOneExists(): void
	{
		$stored  = new IndexData([['id' => 'post-1']]);
		$storage = $this->createMock(IndexRepository::class);
		$storage->method('fetchIndex')->willReturn($stored);

		$builder = $this->createMock(IndexBuilder::class);
		$builder->expects($this->never())->method('buildIndex');

		$reader = new IndexReader($storage, $builder);

		$this->assertSame($stored, $reader->fetchIndex('blog'));
	}

	public function testBuildsTheIndexWhenThereIsNoneOnDisk(): void
	{
		$storage = $this->createMock(IndexRepository::class);
		$storage->method('fetchIndex')->willReturn(null);

		$built   = new IndexData([['id' => 'post-1']]);
		$builder = $this->createMock(IndexBuilder::class);
		$builder->expects($this->once())->method('buildIndex')->with('blog')->willReturn($built);

		$reader = new IndexReader($storage, $builder);

		$this->assertCount(1, $reader->fetchIndex('blog')->objects);
	}

	public function testReturnsTheBuiltContentEvenWhenTheBuildStreamedToDisk(): void
	{
		// A collection over the streaming threshold builds by writing entries
		// straight to the index file and hands back an EMPTY IndexData — the
		// data was never assembled in memory. A caller that returns the build's
		// value verbatim therefore serves an empty index for exactly the
		// collections too large to notice quickly, and every listing on the
		// site shows nothing until something reads the index again.
		//
		// So the reader must answer from what is now on disk rather than from
		// the build's return value.
		$storage = $this->createMock(IndexRepository::class);
		$builder = $this->createMock(IndexBuilder::class);

		$onDisk = null;
		$storage->method('fetchIndex')->willReturnCallback(
			static function () use (&$onDisk): ?IndexData {
				return $onDisk;
			}
		);
		$builder->method('buildIndex')->willReturnCallback(
			static function () use (&$onDisk): IndexData {
				// What the streaming path does: file written, empty value back.
				$onDisk = new IndexData([['id' => 'post-1'], ['id' => 'post-2']]);

				return new IndexData();
			}
		);

		$reader = new IndexReader($storage, $builder);

		$this->assertCount(2, $reader->fetchIndex('blog')->objects);
	}
}
