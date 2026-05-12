<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Event\Listener;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Listener\CollectionMetadataListener;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;

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

		$this->dispatcher->dispatch('object.created', new ObjectEventPayload('posts', 'test-id'));
	}

	public function testObjectUpdatedUpdatesLastUpdated(): void
	{
		$this->collectionSaver
			->expects($this->once())
			->method('updateLastUpdated')
			->with('posts');

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('posts', 'test-id'));
	}

	public function testObjectDeletedDecrementsTotalObjects(): void
	{
		$this->collectionSaver
			->expects($this->once())
			->method('decrementTotalObjects')
			->with('posts');

		$this->dispatcher->dispatch('object.deleted', new ObjectEventPayload('posts', 'test-id'));
	}
}
