<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Event\Listener;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Listener\CollectionMetadataListener;
use TotalCMS\Domain\Event\Payload\ImportEventPayload;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;

final class CollectionMetadataListenerTest extends TestCase
{
	private EventDispatcher $dispatcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionSaver;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;

	protected function setUp(): void
	{
		$this->collectionSaver   = $this->createMock(CollectionSaver::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->dispatcher        = new EventDispatcher(new NullLogger());

		(new CollectionMetadataListener($this->collectionSaver, $this->collectionFetcher))->register($this->dispatcher);
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

	public function testImportCompletedFlushesCreatedCountOnce(): void
	{
		// Drops the in-memory OID bump, then writes the batch's created count
		// to disk in one increment.
		$this->collectionFetcher
			->expects($this->once())
			->method('clearCache')
			->with('posts');

		$this->collectionSaver
			->expects($this->once())
			->method('incrementCount')
			->with('posts', 3);

		$this->dispatcher->dispatch(
			'import.completed',
			new ImportEventPayload('posts', 3, ['a', 'b', 'c']),
		);
	}

	public function testImportCompletedWithNoCreatedObjectsDoesNothing(): void
	{
		$this->collectionSaver->expects($this->never())->method('incrementCount');

		$this->dispatcher->dispatch(
			'import.completed',
			new ImportEventPayload('posts', 0, []),
		);
	}
}
