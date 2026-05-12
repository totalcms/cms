<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Repository\BuilderOrderRepository;
use TotalCMS\Domain\Builder\Service\BuilderOrderService;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

final class BuilderOrderServiceTest extends TestCase
{
	private string $tmpDir;
	private StorageFilesystemAdapter $adapter;
	private IndexReader&MockObject $indexReader;
	private BuilderOrderService $service;

	protected function setUp(): void
	{
		$this->tmpDir      = sys_get_temp_dir() . '/tcms-order-test-' . uniqid();
		mkdir($this->tmpDir, 0755, true);
		mkdir($this->tmpDir . '/builder-pages', 0755, true);

		$flysystem         = new Filesystem(new LocalFilesystemAdapter($this->tmpDir));
		$this->adapter     = new StorageFilesystemAdapter($flysystem);
		$this->indexReader = $this->createMock(IndexReader::class);
		$this->service     = new BuilderOrderService(
			new BuilderOrderRepository($this->adapter),
			$this->indexReader,
		);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmpDir);
	}

	private function setupPages(array $pages): void
	{
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData($pages));
	}

	public function testReadMigratesFromLegacyParentSortFieldsWhenFileMissing(): void
	{
		$this->setupPages([
			['id' => 'home',  'parent' => '',     'sort' => 0],
			['id' => 'about', 'parent' => '',     'sort' => 1],
			['id' => 'blog',  'parent' => '',     'sort' => 2],
			['id' => 'post',  'parent' => 'blog', 'sort' => 0],
		]);

		$tree = $this->service->read('builder-pages');

		$this->assertSame('home', $tree[0]['id']);
		$this->assertSame('about', $tree[1]['id']);
		$this->assertSame('blog', $tree[2]['id']);
		$this->assertCount(1, $tree[2]['children']);
		$this->assertSame('post', $tree[2]['children'][0]['id']);

		// File should now exist (migration writes it on first read)
		$this->assertTrue($this->adapter->fileExists('builder-pages/.order.json'));
	}

	public function testReadAppendsNewPagesNotInOrderFile(): void
	{
		$this->writeOrder([
			['id' => 'home', 'children' => []],
		]);
		$this->setupPages([
			['id' => 'home'],
			['id' => 'about'],   // not in order file
			['id' => 'contact'], // not in order file
		]);

		$tree = $this->service->read('builder-pages');

		$ids = array_column($tree, 'id');
		$this->assertSame(['home', 'about', 'contact'], $ids);
	}

	public function testDeletedParentPromotesChildrenInPlacePreservingStructure(): void
	{
		// Tree: blog → post → sub. Delete `blog`. Without splicing, post and
		// sub would each show up at root flat. With splicing, post takes
		// blog's spot and keeps sub nested inside.
		$this->writeOrder([
			['id' => 'home', 'children' => []],
			['id' => 'blog', 'children' => [
				['id' => 'post', 'children' => [
					['id' => 'sub', 'children' => []],
				]],
			]],
			['id' => 'about', 'children' => []],
		]);
		$this->setupPages([
			['id' => 'home'],
			['id' => 'post'],
			['id' => 'sub'],
			['id' => 'about'],
			// blog is gone
		]);

		$tree = $this->service->read('builder-pages');

		$ids = array_column($tree, 'id');
		$this->assertSame(['home', 'post', 'about'], $ids);

		// post should still own sub
		$postIndex = array_search('post', $ids, true);
		$this->assertCount(1, $tree[$postIndex]['children']);
		$this->assertSame('sub', $tree[$postIndex]['children'][0]['id']);
	}

	public function testReadDropsOrphanIdsFromOrderFile(): void
	{
		$this->writeOrder([
			['id' => 'home',     'children' => []],
			['id' => 'deleted',  'children' => [
				['id' => 'also-deleted', 'children' => []],
			]],
			['id' => 'about',    'children' => []],
		]);
		$this->setupPages([
			['id' => 'home'],
			['id' => 'about'],
		]);

		$tree = $this->service->read('builder-pages');

		$ids = array_column($tree, 'id');
		$this->assertSame(['home', 'about'], $ids);
	}

	public function testReadDeduplicatesRepeatedIds(): void
	{
		$this->writeOrder([
			['id' => 'home',  'children' => []],
			['id' => 'home',  'children' => []],
			['id' => 'about', 'children' => []],
		]);
		$this->setupPages([
			['id' => 'home'],
			['id' => 'about'],
		]);

		$tree = $this->service->read('builder-pages');

		$ids = array_column($tree, 'id');
		$this->assertSame(['home', 'about'], $ids);
	}

	public function testWriteFiltersUnknownIds(): void
	{
		$this->setupPages([
			['id' => 'home'],
			['id' => 'about'],
		]);

		$cleaned = $this->service->write('builder-pages', [
			['id' => 'home',     'children' => []],
			['id' => 'fake',     'children' => []],
			['id' => 'about',    'children' => []],
		]);

		$ids = array_column($cleaned, 'id');
		$this->assertSame(['home', 'about'], $ids);
	}

	public function testWritePreservesNestedHierarchy(): void
	{
		$this->setupPages([
			['id' => 'blog'],
			['id' => 'post-1'],
			['id' => 'post-2'],
		]);

		$cleaned = $this->service->write('builder-pages', [
			['id' => 'blog', 'children' => [
				['id' => 'post-1', 'children' => []],
				['id' => 'post-2', 'children' => []],
			]],
		]);

		$this->assertCount(1, $cleaned);
		$this->assertSame('blog', $cleaned[0]['id']);
		$this->assertCount(2, $cleaned[0]['children']);
		$this->assertSame('post-1', $cleaned[0]['children'][0]['id']);
	}

	public function testParentOfReturnsImmediateParent(): void
	{
		$this->writeOrder([
			['id' => 'blog', 'children' => [
				['id' => 'post', 'children' => []],
			]],
		]);
		$this->setupPages([
			['id' => 'blog'],
			['id' => 'post'],
		]);

		$this->assertSame('blog', $this->service->parentOf('builder-pages', 'post'));
		$this->assertSame('', $this->service->parentOf('builder-pages', 'blog'));
		$this->assertSame('', $this->service->parentOf('builder-pages', 'unknown'));
	}

	public function testEmptyIndexProducesEmptyTree(): void
	{
		$this->setupPages([]);

		$tree = $this->service->read('builder-pages');

		$this->assertSame([], $tree);
	}

	/**
	 * @param list<array<string,mixed>> $tree
	 */
	private function writeOrder(array $tree): void
	{
		file_put_contents(
			$this->tmpDir . '/builder-pages/.order.json',
			(string)json_encode($tree),
		);
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
