<?php

declare(strict_types=1);

namespace Tests\Unit\Export\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Export\Service\ZipBuilder;

/**
 * The temp-zip prelude and the .cache-skipping tree walk that the collection
 * and object zippers used to each carry a copy of.
 */
final class ZipBuilderTest extends TestCase
{
	private string $root = '';

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/tcms-zip-' . uniqid();
		mkdir($this->root . '/assets/.cache/thumbs', 0755, true);
		mkdir($this->root . '/assets/sub', 0755, true);
		file_put_contents($this->root . '/assets/a.txt', 'a');
		file_put_contents($this->root . '/assets/sub/b.txt', 'b');
		file_put_contents($this->root . '/assets/.cache/thumbs/t.jpg', 'x');
		file_put_contents($this->root . '/obj.json', '{}');
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	public function testATreeIsAddedUnderItsZipPathWithoutCacheEntries(): void
	{
		$builder = ZipBuilder::temp('t-' . uniqid());
		$builder->addFile($this->root . '/obj.json', 'obj.json');
		$builder->addTree($this->root . '/assets', 'myid');
		$path = $builder->close();

		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($path));
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = $zip->getNameIndex($i);
		}
		$zip->close();
		unlink($path);

		$this->assertContains('obj.json', $names);
		$this->assertContains('myid/a.txt', $names);
		$this->assertContains('myid/sub/b.txt', $names);
		$this->assertSame([], array_filter($names, fn (string $n): bool => str_contains($n, '.cache')));
	}

	public function testTheTempPathCarriesTheStem(): void
	{
		$builder = ZipBuilder::temp('collection-blog');
		$builder->addFile($this->root . '/obj.json', 'obj.json');
		$path = $builder->close();

		$this->assertStringStartsWith(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'collection-blog-', $path);
		$this->assertStringEndsWith('.zip', $path);
		unlink($path);
	}

	public function testDiscardLeavesNothingBehind(): void
	{
		$builder = ZipBuilder::temp('t-' . uniqid());
		$path    = $builder->path();
		$builder->discard();

		$this->assertFileDoesNotExist($path);
	}

	public function testEntriesCountsWhatWasAdded(): void
	{
		$builder = ZipBuilder::temp('t-' . uniqid());
		$this->assertSame(0, $builder->entries());
		$builder->addFile($this->root . '/obj.json', 'obj.json');
		$this->assertSame(1, $builder->entries());
		$builder->discard();
	}

	public function testHasNonCacheContentsIgnoresTheCacheFolder(): void
	{
		mkdir($this->root . '/onlycache/.cache', 0755, true);

		$this->assertTrue(ZipBuilder::hasNonCacheContents($this->root . '/assets'));
		$this->assertFalse(ZipBuilder::hasNonCacheContents($this->root . '/onlycache'));
	}
}
