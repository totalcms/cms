<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Event\Listener;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\DataView\Service\DataViewUpdateScheduler;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Listener\DataViewListener;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;

final class DataViewListenerTest extends TestCase
{
	private EventDispatcher $dispatcher;
	private \PHPUnit\Framework\MockObject\MockObject $viewUpdateScheduler;

	protected function setUp(): void
	{
		$this->viewUpdateScheduler = $this->createMock(DataViewUpdateScheduler::class);
		$this->dispatcher          = new EventDispatcher(new NullLogger());

		(new DataViewListener($this->viewUpdateScheduler))->register($this->dispatcher);
	}

	public function testObjectCreatedSchedulesViewUpdates(): void
	{
		$this->viewUpdateScheduler
			->expects($this->once())
			->method('scheduleUpdatesForCollection')
			->with('posts');

		$this->dispatcher->dispatch('object.created', new ObjectEventPayload('posts', 'test-id'));
	}

	public function testObjectUpdatedSchedulesViewUpdates(): void
	{
		$this->viewUpdateScheduler
			->expects($this->once())
			->method('scheduleUpdatesForCollection')
			->with('posts');

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('posts', 'test-id'));
	}

	public function testObjectDeletedSchedulesViewUpdates(): void
	{
		$this->viewUpdateScheduler
			->expects($this->once())
			->method('scheduleUpdatesForCollection')
			->with('posts');

		$this->dispatcher->dispatch('object.deleted', new ObjectEventPayload('posts', 'test-id'));
	}
}
