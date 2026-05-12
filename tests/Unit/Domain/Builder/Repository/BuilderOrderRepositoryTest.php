<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Repository;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Repository\BuilderOrderRepository;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

final class BuilderOrderRepositoryTest extends TestCase
{
	private string $tmpDir;
	private StorageFilesystemAdapter $adapter;
	private BuilderOrderRepository $repo;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/tcms-order-repo-test-' . uniqid();
		mkdir($this->tmpDir, 0755, true);
		mkdir($this->tmpDir . '/builder-pages', 0755, true);

		$flysystem     = new Filesystem(new LocalFilesystemAdapter($this->tmpDir));
		$this->adapter = new StorageFilesystemAdapter($flysystem);
		$this->repo    = new BuilderOrderRepository($this->adapter);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmpDir);
	}

	public function testExistsReturnsFalseWhenFileMissing(): void
	{
		$this->assertFalse($this->repo->exists('builder-pages'));
	}

	public function testExistsReturnsTrueAfterWrite(): void
	{
		$this->repo->write('builder-pages', [['id' => 'home', 'children' => []]]);
		$this->assertTrue($this->repo->exists('builder-pages'));
	}

	public function testWriteThenReadRoundTrip(): void
	{
		$tree = [
			['id' => 'home', 'children' => []],
			['id' => 'blog', 'children' => [
				['id' => 'post', 'children' => []],
			]],
		];

		$this->repo->write('builder-pages', $tree);

		$this->assertSame($tree, $this->repo->read('builder-pages'));
	}

	public function testReadReturnsEmptyWhenFileMissing(): void
	{
		$this->assertSame([], $this->repo->read('builder-pages'));
	}

	public function testReadReturnsEmptyWhenJsonIsMalformed(): void
	{
		file_put_contents($this->tmpDir . '/builder-pages/.order.json', 'not json {');

		$this->assertSame([], $this->repo->read('builder-pages'));
	}

	public function testReadReturnsEmptyWhenJsonIsNotAnArray(): void
	{
		file_put_contents($this->tmpDir . '/builder-pages/.order.json', '"a string"');

		$this->assertSame([], $this->repo->read('builder-pages'));
	}

	public function testReadFiltersOutNonArrayNodes(): void
	{
		file_put_contents(
			$this->tmpDir . '/builder-pages/.order.json',
			(string)json_encode([
				['id' => 'home', 'children' => []],
				'not-an-object',
				42,
				['id' => 'about', 'children' => []],
			]),
		);

		$tree = $this->repo->read('builder-pages');

		$this->assertCount(2, $tree);
		$this->assertSame('home', $tree[0]['id']);
		$this->assertSame('about', $tree[1]['id']);
	}

	public function testWriteIsPrettyPrinted(): void
	{
		$this->repo->write('builder-pages', [['id' => 'home', 'children' => []]]);

		$contents = (string)file_get_contents($this->tmpDir . '/builder-pages/.order.json');
		$this->assertStringContainsString("\n", $contents);
		$this->assertStringContainsString('"id": "home"', $contents);
	}

	public function testFileLandsInCollectionDir(): void
	{
		$this->repo->write('my-pages', [['id' => 'home', 'children' => []]]);

		$this->assertFileExists($this->tmpDir . '/my-pages/.order.json');
	}

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = scandir($dir);
		if ($items === false) {
			return;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}
}
