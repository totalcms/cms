<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Event\Listener;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Listener\IndexBuildListener;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;

final class IndexBuildListenerTest extends TestCase
{
	private EventDispatcher $dispatcher;
	private \PHPUnit\Framework\MockObject\MockObject $indexBuilder;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionLister;

	protected function setUp(): void
	{
		$this->indexBuilder      = $this->createMock(IndexBuilder::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->collectionLister  = $this->createMock(CollectionLister::class);
		$this->dispatcher        = new EventDispatcher(new NullLogger());

		(new IndexBuildListener(
			$this->indexBuilder,
			$this->collectionFetcher,
			$this->collectionLister,
		))->register($this->dispatcher);
	}

	public function testObjectCreatedCallsSmartBuildIndexWithObject(): void
	{
		$object = $this->createMock(ObjectData::class);

		$this->indexBuilder
			->expects($this->once())
			->method('smartBuildIndex')
			->with('posts', $object);

		$this->dispatcher->dispatch('object.created', [
			'collection' => 'posts',
			'id'         => 'test-id',
			'object'     => $object,
		]);
	}

	public function testObjectUpdatedCallsSmartBuildIndexWithObject(): void
	{
		$object = $this->createMock(ObjectData::class);

		$this->indexBuilder
			->expects($this->once())
			->method('smartBuildIndex')
			->with('posts', $object);

		$this->dispatcher->dispatch('object.updated', [
			'collection' => 'posts',
			'id'         => 'test-id',
			'object'     => $object,
		]);
	}

	public function testObjectDeletedWithQueueRebuildCallsBothMethods(): void
	{
		$mockCollection                     = $this->createMock(CollectionData::class);
		$mockCollection->queueRebuildOnSave = true;

		$this->collectionFetcher
			->expects($this->once())
			->method('fetchCollection')
			->with('posts')
			->willReturn($mockCollection);

		$this->indexBuilder
			->expects($this->once())
			->method('removeObjectFromIndex')
			->with('posts', 'test-id');

		$this->indexBuilder
			->expects($this->once())
			->method('smartBuildIndex')
			->with('posts');

		$this->dispatcher->dispatch('object.deleted', [
			'collection' => 'posts',
			'id'         => 'test-id',
		]);
	}

	public function testObjectDeletedWithoutQueueRebuildCallsOnlySmartBuild(): void
	{
		$mockCollection = $this->createMock(CollectionData::class);

		$this->collectionFetcher
			->expects($this->once())
			->method('fetchCollection')
			->with('posts')
			->willReturn($mockCollection);

		$this->indexBuilder
			->expects($this->never())
			->method('removeObjectFromIndex');

		$this->indexBuilder
			->expects($this->once())
			->method('smartBuildIndex')
			->with('posts');

		$this->dispatcher->dispatch('object.deleted', [
			'collection' => 'posts',
			'id'         => 'test-id',
		]);
	}

	public function testSchemaSavedRebuildsIndexForMatchingCollections(): void
	{
		$collection1         = $this->createMock(CollectionData::class);
		$collection1->id     = 'posts';
		$collection1->schema = 'blog';

		$collection2         = $this->createMock(CollectionData::class);
		$collection2->id     = 'team';
		$collection2->schema = 'team-member';

		$collection3         = $this->createMock(CollectionData::class);
		$collection3->id     = 'news';
		$collection3->schema = 'blog';

		$this->collectionLister
			->expects($this->once())
			->method('listAllCollections')
			->willReturn([$collection1, $collection2, $collection3]);

		// Should rebuild index only for collections using the 'blog' schema
		$this->indexBuilder
			->expects($this->exactly(2))
			->method('smartBuildIndex')
			->willReturnCallback(function (string $collection): void {
				expect($collection)->toBeIn(['posts', 'news']);
			});

		$this->dispatcher->dispatch('schema.saved', [
			'schema' => 'blog',
		]);
	}
}
