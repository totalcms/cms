<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Event\Listener;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Listener\CollectionMetadataListener;

final class CollectionMetadataListenerTest extends TestCase
{
	private EventDispatcher $dispatcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionSaver;

	protected function setUp(): void
	{
		$this->collectionSaver = $this->createMock(CollectionSaver::class);
		$this->dispatcher      = new EventDispatcher(new NullLogger());

		(new CollectionMetadataListener($this->collectionSaver))->register($this->dispatcher);
	}

	public function testObjectCreatedIncrementsCountAndTotalObjects(): void
	{
		$this->collectionSaver
			->expects($this->once())
			->method('incrementCount')
			->with('posts');

		$this->collectionSaver
			->expects($this->once())
			->method('incrementTotalObjects')
			->with('posts');

		$this->dispatcher->dispatch('object.created', [
			'collection' => 'posts',
			'id'         => 'test-id',
		]);
	}

	public function testObjectUpdatedUpdatesLastUpdated(): void
	{
		$this->collectionSaver
			->expects($this->once())
			->method('updateLastUpdated')
			->with('posts');

		$this->dispatcher->dispatch('object.updated', [
			'collection' => 'posts',
			'id'         => 'test-id',
		]);
	}

	public function testObjectDeletedDecrementsTotalObjects(): void
	{
		$this->collectionSaver
			->expects($this->once())
			->method('decrementTotalObjects')
			->with('posts');

		$this->dispatcher->dispatch('object.deleted', [
			'collection' => 'posts',
			'id'         => 'test-id',
		]);
	}
}
