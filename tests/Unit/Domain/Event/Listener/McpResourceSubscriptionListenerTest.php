<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Event\Listener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Event\Listener\McpResourceSubscriptionListener;
use TotalCMS\Domain\Mcp\Subscription\Service\ResourceNotifier;

final class McpResourceSubscriptionListenerTest extends TestCase
{
	/** @var MockObject&ResourceNotifier */
	private MockObject $notifier;

	private McpResourceSubscriptionListener $listener;

	protected function setUp(): void
	{
		$this->notifier = $this->createMock(ResourceNotifier::class);
		$this->listener = new McpResourceSubscriptionListener($this->notifier);
	}

	public function testObjectCreatedDispatchesNotificationForCollectionUri(): void
	{
		$this->notifier->expects($this->once())
			->method('notifyResourceChanged')
			->with('tcms://blog/');

		$this->listener->onObjectCreated(['collection' => 'blog', 'id' => 'hello-world']);
	}

	public function testObjectUpdatedDispatchesNotificationForCollectionUri(): void
	{
		$this->notifier->expects($this->once())
			->method('notifyResourceChanged')
			->with('tcms://products/');

		$this->listener->onObjectUpdated(['collection' => 'products', 'id' => 'sku-1']);
	}

	public function testObjectDeletedDispatchesNotificationForCollectionUri(): void
	{
		$this->notifier->expects($this->once())
			->method('notifyResourceChanged')
			->with('tcms://gallery/');

		$this->listener->onObjectDeleted(['collection' => 'gallery', 'id' => 'photo-1']);
	}

	public function testPayloadWithoutCollectionSkipsNotification(): void
	{
		// Defensive guard — listener silently returns when the payload doesn't
		// carry a usable collection name.
		$this->notifier->expects($this->never())
			->method('notifyResourceChanged');

		$this->listener->onObjectCreated(['id' => 'orphan']);
		$this->listener->onObjectCreated(['collection' => '', 'id' => 'orphan']);
	}

	public function testCoalescesDuplicateEventsForSameUriWithinOneSecond(): void
	{
		// Two object.updated events on the same collection back-to-back within
		// the same request collapse to a single notification — prevents notify
		// storms during sweeps that fire many events.
		$this->notifier->expects($this->once())
			->method('notifyResourceChanged')
			->with('tcms://blog/');

		$payload = ['collection' => 'blog', 'id' => 'post-1'];
		$this->listener->onObjectUpdated($payload);
		$this->listener->onObjectUpdated($payload);
		$this->listener->onObjectUpdated($payload);
	}

	public function testDifferentUrisDoNotCoalesceWithEachOther(): void
	{
		// Two events for different collections within the same request both
		// produce notifications — coalescing is per-URI, not global.
		$this->notifier->expects($this->exactly(2))
			->method('notifyResourceChanged');

		$this->listener->onObjectCreated(['collection' => 'blog', 'id' => 'p1']);
		$this->listener->onObjectCreated(['collection' => 'products', 'id' => 'sku-1']);
	}

	public function testCreatedAndUpdatedOnSameUriCoalesce(): void
	{
		// All three event kinds share the coalescing window for a given URI.
		$this->notifier->expects($this->once())
			->method('notifyResourceChanged')
			->with('tcms://blog/');

		$this->listener->onObjectCreated(['collection' => 'blog', 'id' => 'a']);
		$this->listener->onObjectUpdated(['collection' => 'blog', 'id' => 'a']);
		$this->listener->onObjectDeleted(['collection' => 'blog', 'id' => 'a']);
	}

	// ── Phase 2 Chunk F: dataviews branch ───────────────────────────────────

	public function testDataviewsCollectionMapsToPerViewUri(): void
	{
		// When a dataview object changes (typically because DataViewBuilder
		// finished a rebuild), the listener fires notifications on the per-view
		// URI tcms://view/{viewId} rather than the collection-level URI
		// tcms://dataviews/. This is the bounded exception to the
		// collection-level-only subscription rule from the Phase 2 spec.
		$this->notifier->expects($this->once())
			->method('notifyResourceChanged')
			->with('tcms://view/monthly-sales-summary');

		$this->listener->onObjectUpdated([
			'collection' => 'dataviews',
			'id'         => 'monthly-sales-summary',
		]);
	}

	public function testDataviewsPayloadWithoutIdSkipsNotification(): void
	{
		// Defensive: a dataviews event without an id can't map to a URI.
		// Listener returns silently rather than misrouting to tcms://view//.
		$this->notifier->expects($this->never())
			->method('notifyResourceChanged');

		$this->listener->onObjectUpdated(['collection' => 'dataviews']);
		$this->listener->onObjectUpdated(['collection' => 'dataviews', 'id' => '']);
	}

	public function testDataviewsViewIdsAreSeparateUrisForCoalescing(): void
	{
		// Two events on DIFFERENT views in the same request both fire notifications —
		// coalescing is per-URI, and per-view URIs are distinct.
		$this->notifier->expects($this->exactly(2))
			->method('notifyResourceChanged');

		$this->listener->onObjectUpdated(['collection' => 'dataviews', 'id' => 'view-a']);
		$this->listener->onObjectUpdated(['collection' => 'dataviews', 'id' => 'view-b']);
	}
}
