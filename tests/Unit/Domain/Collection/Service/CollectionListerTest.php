<?php

namespace Tests\Unit\Domain\Collection\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionLister;

final class CollectionListerTest extends TestCase
{
	private CollectionLister $lister;
	private \PHPUnit\Framework\MockObject\MockObject $storage;

	protected function setUp(): void
	{
		$this->storage = $this->createMock(CollectionRepository::class);
		$this->lister  = new CollectionLister($this->storage);
	}

	public function testListAllCollections(): void
	{
		$collections = [
			$this->createCollectionData('blog'),
			$this->createCollectionData('products'),
		];

		$this->storage->expects($this->once())
			->method('listAllCollections')
			->willReturn($collections);

		$result = $this->lister->listAllCollections();

		$this->assertSame($collections, $result);
		$this->assertCount(2, $result);
	}

	public function testListAllCollectionsReturnsEmptyArray(): void
	{
		$this->storage->expects($this->once())
			->method('listAllCollections')
			->willReturn([]);

		$result = $this->lister->listAllCollections();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testListCustomCollectionsFiltersReserved(): void
	{
		$allCollections = [
			$this->createCollectionData('blog'),      // reserved
			$this->createCollectionData('custom-1'),  // custom
			$this->createCollectionData('page'),      // reserved
			$this->createCollectionData('custom-2'),  // custom
		];

		$this->storage->expects($this->once())
			->method('listAllCollections')
			->willReturn($allCollections);

		$this->storage->method('isReservedCollection')
			->willReturnMap([
				['blog', true],
				['custom-1', false],
				['page', true],
				['custom-2', false],
			]);

		$result = $this->lister->listCustomCollections();

		$this->assertCount(2, $result);
		$this->assertContains($allCollections[1], $result);
		$this->assertContains($allCollections[3], $result);
	}

	public function testListCustomCollectionsReturnsEmptyWhenAllReserved(): void
	{
		$allCollections = [
			$this->createCollectionData('blog'),
			$this->createCollectionData('page'),
		];

		$this->storage->method('listAllCollections')->willReturn($allCollections);
		$this->storage->method('isReservedCollection')->willReturn(true);

		$result = $this->lister->listCustomCollections();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testListCustomCollectionsReturnsAllWhenNoneReserved(): void
	{
		$allCollections = [
			$this->createCollectionData('custom-1'),
			$this->createCollectionData('custom-2'),
		];

		$this->storage->method('listAllCollections')->willReturn($allCollections);
		$this->storage->method('isReservedCollection')->willReturn(false);

		$result = $this->lister->listCustomCollections();

		$this->assertCount(2, $result);
		$this->assertSame($allCollections, array_values($result));
	}

	public function testListCollectionsWithSchemaFiltersCorrectly(): void
	{
		$allCollections = [
			$this->createCollectionData('blog', 'blog'),
			$this->createCollectionData('posts', 'blog'),
			$this->createCollectionData('products', 'product'),
			$this->createCollectionData('items', 'product'),
		];

		$this->storage->expects($this->once())
			->method('listAllCollections')
			->willReturn($allCollections);

		$result = $this->lister->listCollectionsWithSchema('blog');

		$this->assertCount(2, $result);
		$this->assertContains($allCollections[0], $result);
		$this->assertContains($allCollections[1], $result);
	}

	public function testListCollectionsWithSchemaReturnsEmptyWhenNoMatch(): void
	{
		$allCollections = [
			$this->createCollectionData('blog', 'blog'),
			$this->createCollectionData('products', 'product'),
		];

		$this->storage->method('listAllCollections')->willReturn($allCollections);

		$result = $this->lister->listCollectionsWithSchema('nonexistent');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testListCollectionsWithSchemaReturnsAllWhenAllMatch(): void
	{
		$allCollections = [
			$this->createCollectionData('blog-1', 'blog'),
			$this->createCollectionData('blog-2', 'blog'),
			$this->createCollectionData('blog-3', 'blog'),
		];

		$this->storage->method('listAllCollections')->willReturn($allCollections);

		$result = $this->lister->listCollectionsWithSchema('blog');

		$this->assertCount(3, $result);
		$this->assertSame($allCollections, array_values($result));
	}

	public function testListCollectionsWithSchemaCaseSensitive(): void
	{
		$allCollections = [
			$this->createCollectionData('blog', 'blog'),
			$this->createCollectionData('products', 'Blog'), // Different case
		];

		$this->storage->method('listAllCollections')->willReturn($allCollections);

		$result = $this->lister->listCollectionsWithSchema('blog');

		$this->assertCount(1, $result);
		$this->assertContains($allCollections[0], $result);
	}

	private function createCollectionData(string $id, string $schema = 'generic'): CollectionData
	{
		$collection              = new CollectionData();
		$collection->id          = $id;
		$collection->name        = ucfirst($id);
		$collection->schema      = $schema;
		$collection->description = "Test {$id} collection";

		return $collection;
	}
}
